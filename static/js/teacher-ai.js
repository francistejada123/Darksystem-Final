(function(){
    'use strict';
    var root=document.getElementById('teacherAiRoot'); if(!root) return;
    var drawer=document.getElementById('teacherAiDrawer'), body=document.getElementById('teacherAiBody'), input=document.getElementById('teacherAiInput'), form=document.getElementById('teacherAiForm'), sendBtn=document.getElementById('teacherAiSend');
    var welcome=document.getElementById('teacherAiWelcome'), historyPanel=document.getElementById('teacherAiHistoryPanel'), histories=[]; var current={id:newId(),title:'New conversation',turns:[]};
    // Start a clean history namespace after the old invalid-model failures;
    // stale "temporarily unavailable" bubbles must not look like new replies.
    var storageKey='egradepro-teacher-ai-history-v2';
    try{histories=JSON.parse(localStorage.getItem(storageKey)||'[]'); if(!Array.isArray(histories)) histories=[];}catch(e){histories=[];}
    function save(){try{localStorage.setItem(storageKey,JSON.stringify(histories.slice(0,20)));}catch(e){}}
    function newId(){return Date.now().toString(36)+Math.random().toString(36).slice(2,6);}
    // One saved entry per conversation (matched by id) instead of a new copy on every reply.
    function saveCurrent(){histories=histories.filter(function(h){return h.id!==current.id;});histories.unshift(JSON.parse(JSON.stringify(current)));save();}
    function open(){root.classList.add('is-open'); drawer.setAttribute('aria-hidden','false'); applySubjectCards(); setTimeout(function(){input.focus();},250);}
    function close(){root.classList.remove('is-open'); drawer.setAttribute('aria-hidden','true'); if(typeof closePlusMenu==='function')closePlusMenu();}
    function escapeHtml(s){return String(s).replace(/[&<>"']/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];});}
    function format(text){
        // Decode a few common HTML entities the model sometimes writes (e.g. &middot;) so they show as characters, not code.
        var ents={'&middot;':'·','&ndash;':'–','&mdash;':'—','&nbsp;':' ','&amp;':'&','&quot;':'"','&#39;':"'",'&lt;':'<','&gt;':'>'};
        var normalized=String(text||'').replace(/&(middot|ndash|mdash|nbsp|amp|quot|#39|lt|gt);/g,function(m){return ents[m];}).replace(/\r\n?/g,'\n').replace(/\*\*(.*?)\*\*/g,'$1').replace(/__(.*?)__/g,'$1').replace(/`([^`]+)`/g,'$1');
        var lines=normalized.split(/\n/), html='', listType='', listOpen=false;
        function closeList(){if(listOpen){html+='</'+listType+'>';listOpen=false;listType='';}}
        function openList(type){if(listType!==type){closeList();html+='<'+type+'>';listType=type;listOpen=true;}}
        lines.forEach(function(raw){
            var line=raw.trim(), heading=line.match(/^#{1,3}\s+(.+)/), number=line.match(/^(\d+)\.\s+(.+)/), bullet=line.match(/^[-*]\s+(.+)/), letter=line.match(/^([A-Z])[\.)]\s+(.+)/);
            if(!line){closeList();return;}
            if(heading){closeList();html+='<h3>'+escapeHtml(heading[1])+'</h3>';return;}
            if(number){openList('ol');html+='<li value="'+parseInt(number[1],10)+'">'+escapeHtml(number[2])+'</li>';return;}
            if(bullet){openList('ul');html+='<li>'+escapeHtml(bullet[1])+'</li>';return;}
            if(letter){openList('ul');html+='<li class="teacher-ai-choice"><span>'+escapeHtml(letter[1])+'.</span>'+escapeHtml(letter[2])+'</li>';return;}
            closeList();html+='<p>'+escapeHtml(line)+'</p>';
        });
        closeList();
        return html||'<p></p>';
    }
    function addMessage(role,text,actions,exportFormat){if(welcome) welcome.style.display='none'; var el=document.createElement('article'); el.className='teacher-ai-message '+role; if(exportFormat) el.dataset.exportFormat=exportFormat; el.innerHTML=role==='ai'?format(text):'<p>'+escapeHtml(text)+'</p>'; if(actions){var bar=document.createElement('div');bar.className='teacher-ai-message-actions'; var copy=document.createElement('button');copy.type='button';copy.innerHTML='<i class="fas fa-copy"></i> Copy';copy.onclick=function(){navigator.clipboard&&navigator.clipboard.writeText(text);};bar.appendChild(copy);var regenerate=document.createElement('button');regenerate.type='button';regenerate.innerHTML='<i class="fas fa-rotate"></i> Regenerate';regenerate.onclick=function(){var last=current.turns.filter(function(t){return t.role==='user';}).pop();if(last)send(last.text);};bar.appendChild(regenerate);el.appendChild(bar);} body.appendChild(el);body.scrollTop=body.scrollHeight; return el;}
    function renderHistory(){historyPanel.innerHTML=''; if(!histories.length){historyPanel.innerHTML='<div class="teacher-ai-history-empty">No saved chats yet.</div>';return;} histories.forEach(function(chat,i){var row=document.createElement('div');row.className='teacher-ai-history-item';row.innerHTML='<i class="fas fa-message"></i><span title="Double-click to rename">'+escapeHtml(chat.title||'Conversation')+'</span><button type="button" class="teacher-ai-history-delete" aria-label="Delete chat"><i class="fas fa-trash"></i></button>';row.querySelector('span').onclick=function(){loadChat(i);};row.querySelector('span').ondblclick=function(e){e.stopPropagation();var title=window.prompt('Rename this chat',chat.title||'Conversation');if(title&&title.trim()){chat.title=title.trim();save();renderHistory();}};row.querySelector('.teacher-ai-history-delete').onclick=function(e){e.stopPropagation();histories.splice(i,1);save();renderHistory();};historyPanel.appendChild(row);});}
    function loadChat(i){if(!histories[i].id)histories[i].id=newId();current=JSON.parse(JSON.stringify(histories[i]));renderSubjectBar();body.innerHTML='';current.turns.forEach(function(t){if(t.role==='ai'&&t.resource)addResourceMessage(t);else if(t.role==='ai'&&t.dashboard)addDashboardMessage(t.dashboard);else{var m=addMessage(t.role,t.text,t.role==='ai');if(t.role==='user'&&t.attachment_name)addAttachmentNote(m,t.attachment_name);}});historyPanel.hidden=true;}
    function welcomeMarkup(){return '<div class="teacher-ai-welcome-icon"><i class="fas fa-robot"></i></div><h2>E-GRADEPRO Teacher AI</h2><p>Your intelligent teaching and school assistant.</p><p class="teacher-ai-welcome-copy">Ask about teaching, classes, or your authorized students\' attendance, activity, missing work, grades, and at-risk indicators. Optionally analyze topic-level performance from your recorded quiz, activity, and exam scores. Create lessons, quizzes, and activities, turn them into PDF, Word, PowerPoint, or Google Forms, and send them to your own classes.</p><div class="teacher-ai-suggestions"><button type="button" data-chat-type="lesson"><i class="fas fa-book-open"></i><span><b>Lesson Planning</b>Create a lesson plan for your subject and grade level</span></button><button type="button" data-chat-type="assessment"><i class="fas fa-pen-to-square"></i><span><b>Assessment</b>Create a quiz about any topic you choose</span></button><button type="button" data-chat-type="explain"><i class="fas fa-lightbulb"></i><span><b>Explain a Topic</b>Explain any topic you choose, in simple terms</span></button><button type="button" data-chat-type="strategy"><i class="fas fa-bullseye"></i><span><b>Teaching Strategy</b>Give me strategies for struggling learners</span></button><button type="button" data-prompt="How does the grading module work in E-GRADEPRO?"><i class="fas fa-school"></i><span><b>E-GRADEPRO Help</b>How does the grading module work?</span></button><button type="button" data-dashboard="student_monitoring"><i class="fas fa-user-check"></i><span><b>Student Monitoring</b>Performance analytics: strong/weak topics, trends, and who to watch</span></button><button type="button" data-prompt="Analyze my students\' assessment performance by topic. Which topics are strong or weak, class-wide and for individual students, and which students may need monitoring?"><i class="fas fa-chart-line"></i><span><b>Student Performance Analysis</b>Strong/weak topics from actual quiz, activity, and exam scores</span></button></div>';}
    function bindWelcomeOptions(){if(!welcome)return;welcome.querySelectorAll('[data-chat-type]').forEach(function(btn){btn.onclick=function(){open();startChatPrompt(btn.getAttribute('data-chat-type'));};});welcome.querySelectorAll('[data-prompt]').forEach(function(btn){btn.onclick=function(){open();send(btn.getAttribute('data-prompt'));};});welcome.querySelectorAll('[data-dashboard]').forEach(function(btn){btn.onclick=function(){open();sendDashboard(btn.getAttribute('data-dashboard'));};});}
    function newChat(){if(current.turns.length){saveCurrent();} current={id:newId(),title:'New conversation',turns:[]};body.innerHTML='';historyPanel.hidden=true;welcome=document.createElement('section');welcome.className='teacher-ai-welcome';welcome.id='teacherAiWelcome';welcome.innerHTML=welcomeMarkup();body.appendChild(welcome);bindWelcomeOptions();renderSubjectBar();applySubjectCards();input.value='';clearAttachment();input.focus();}
    function setDraft(text){input.value=text||'';input.dispatchEvent(new Event('input'));input.focus();input.setSelectionRange(input.value.length,input.value.length);}
    function startChatPrompt(type){
        var prompts={
            lesson:'I want to create a lesson plan. Please ask me for the subject, grade level, section, topic, and learning objectives one at a time.',
            assessment:'I want to create an assessment. Please ask me for the subject, grade level, topic, and number of items one at a time.',
            explain:'I want you to explain a topic to my students. Please ask me for the subject, grade level, topic, and preferred difficulty one at a time.',
            strategy:'I need teaching strategies for struggling learners. Please ask me about the subject, grade level, learner needs, and classroom situation one at a time.'
        };
        send(prompts[type]||'I need help with my teaching. Please ask me what I need first.');
    }
    // ---- Subject mode: the welcome screen lists the teacher's OWN subjects (from the
    // server, which only returns assigned classes). Choosing one limits the whole
    // chat -- and anything generated in it -- to that subject. If the list cannot
    // be loaded the original starter cards stay, so nothing is lost. ----
    var subjectList=null, subjectBar=null, defaultPlaceholder=input.placeholder||'';
    function subjectsFromClasses(classes){
        var order=[], map={};
        classes.forEach(function(c){
            if(!map[c.subject_code]){map[c.subject_code]={code:c.subject_code,name:c.subject_name,grades:{},gradeOrder:[]};order.push(c.subject_code);}
            var sub=map[c.subject_code];
            if(!sub.grades[c.grade_level]){sub.grades[c.grade_level]=[];sub.gradeOrder.push(c.grade_level);}
            if(sub.grades[c.grade_level].indexOf(c.section_name)<0)sub.grades[c.grade_level].push(c.section_name);
        });
        // Lowest grade level first (Grade 7 -> Grade 12), then by name; sections inside a grade are in natural order too.
        function gradeNum(g){var m=String(g||'').match(/\d+/);return m?parseInt(m[0],10):999;}
        return order.map(function(code){
            var sub=map[code];
            sub.gradeOrder.sort(function(a,b){return gradeNum(a)-gradeNum(b);});
            sub.detail=sub.gradeOrder.map(function(g){return g+' \u2022 '+sub.grades[g].sort(function(a,b){return String(a).localeCompare(String(b),undefined,{numeric:true});}).join(', ');}).join(' | ');
            return {code:sub.code,name:sub.name,detail:sub.detail,gn:gradeNum(sub.gradeOrder[0])};
        }).sort(function(a,b){return (a.gn-b.gn)||a.name.localeCompare(b.name,undefined,{numeric:true});});
    }
    function loadSubjects(){
        if(subjectList)return Promise.resolve(subjectList);
        if(!root.dataset.resourceEndpoint)return Promise.resolve([]);
        return fetch(root.dataset.resourceEndpoint,{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':root.dataset.csrf},credentials:'same-origin',body:JSON.stringify({csrf_token:root.dataset.csrf,action:'classes'})})
            .then(function(r){return r.json();})
            .then(function(d){if(d&&d.success&&Array.isArray(d.classes)&&d.classes.length)subjectList=subjectsFromClasses(d.classes);return subjectList||[];})
            .catch(function(){return [];});
    }
    function applySubjectCards(){
        var w=welcome; if(!w||current.subject||w.dataset.subjects==='1'||w.dataset.subjects==='loading')return;
        w.dataset.subjects='loading';
        loadSubjects().then(function(list){
            if(!list.length){w.dataset.subjects='';return;}
            if(!w.isConnected||current.subject||w.dataset.subjectHome==='1')return;
            var box=w.querySelector('.teacher-ai-suggestions'); if(!box)return;
            box.innerHTML=list.map(function(sub,i){return '<button type="button" data-subject-index="'+i+'"><i class="fas fa-book"></i><span><b>'+escapeHtml(sub.name)+'</b>'+escapeHtml(sub.detail)+'</span></button>';}).join('');
            if(!w.querySelector('.tai-subject-hint')){var hint=document.createElement('p');hint.className='tai-subject-hint';hint.innerHTML='<span class="tai-hint-icon"><i class="fas fa-circle-info"></i></span><span class="tai-hint-text"><b>Choose a subject to get started</b>Select one of your subjects and Teacher AI will help you with that subject. You can also type a general question below.</span>';box.parentNode.insertBefore(hint,box);}
            box.querySelectorAll('[data-subject-index]').forEach(function(btn){btn.onclick=function(){open();enterSubject(list[parseInt(btn.getAttribute('data-subject-index'),10)]);};});
            w.dataset.subjects='1';
        });
    }
    function renderSubjectBar(){
        if(!subjectBar){subjectBar=document.createElement('div');subjectBar.className='tai-subject-bar';subjectBar.hidden=true;body.parentNode.insertBefore(subjectBar,body);}
        if(!current.subject){subjectBar.hidden=true;subjectBar.innerHTML='';input.placeholder=defaultPlaceholder;return;}
        subjectBar.innerHTML='<span class="tai-subject-name"><i class="fas fa-book"></i> <b>'+escapeHtml(current.subject.name)+'</b> <em>'+escapeHtml(current.subject.detail||'')+'</em></span><button type="button">Change subject</button>';
        subjectBar.querySelector('button').onclick=newChat;
        subjectBar.hidden=false;
        input.placeholder='Ask about '+current.subject.name+'…';
    }
    function enterSubject(sub){
        if(!sub)return;
        if(current.turns.length){saveCurrent();current={id:newId(),title:'New conversation',turns:[]};body.innerHTML='';welcome=document.createElement('section');welcome.className='teacher-ai-welcome';welcome.id='teacherAiWelcome';body.appendChild(welcome);}
        current.subject={code:sub.code,name:sub.name,detail:sub.detail};
        renderSubjectBar();
        var n=sub.name, prompts=[
            ['fa-book-open','Lesson Plan','Create a lesson plan for '+n,'I want to create a lesson plan for '+n+'. Please ask me for the topic and learning objectives one at a time.'],
            ['fa-pen-to-square','Quiz','Create a quiz for '+n,'I want to create a quiz for '+n+'. Please ask me for the topic and number of items one at a time.'],
            ['fa-pencil','Activity / Worksheet','Classroom activity or worksheet for '+n,'I want to create a classroom activity or worksheet for '+n+'. Please ask me for the topic and objective one at a time.'],
            ['fa-clipboard-check','Assessment','Short test or performance task for '+n,'I want to create an assessment for '+n+'. Please ask me for the topic, type of assessment and number of items one at a time.'],
            ['fa-lightbulb','Explain a Topic','Explain a '+n+' topic in simple terms','I want you to explain a '+n+' topic to my students in simple terms. Please ask me which topic.'],
            ['fa-user-check','Student Monitoring','Strong/weak topics and who to watch in '+n,null,'student_monitoring']
        ];
        welcome.dataset.subjectHome='1';welcome.style.display='';
        welcome.innerHTML='<div class="teacher-ai-welcome-icon"><i class="fas fa-book"></i></div><h2>'+escapeHtml(n)+'</h2><p>'+escapeHtml(sub.detail||'')+'</p><p class="teacher-ai-welcome-copy">Everything here is about '+escapeHtml(n)+' only. What would you like to do?</p><div class="teacher-ai-suggestions">'
            +prompts.map(function(p,i){return '<button type="button" data-subject-prompt="'+i+'"><i class="fas '+p[0]+'"></i><span><b>'+escapeHtml(p[1])+'</b>'+escapeHtml(p[2])+'</span></button>';}).join('')+'</div>';
        welcome.querySelectorAll('[data-subject-prompt]').forEach(function(btn){btn.onclick=function(){var p=prompts[parseInt(btn.getAttribute('data-subject-prompt'),10)];if(p[4])sendDashboard(p[4]);else send(p[3]);};});
        input.focus();
    }
    function requestedExportFormat(text){if(/\b(pdf|portable document format|downloadable pdf)\b/i.test(text))return 'pdf';if(/\b(ppt|pptx|powerpoint|presentation|slides?)\b/i.test(text))return 'ppt';return '';}
    function turnsForServer(){return current.turns.slice(0,-1).map(function(t){return {role:t.role,text:t.text+(t.attachment_name?' [attached file: '+t.attachment_name+']':'')};});}
    function addResourceMessage(turn){
        if(welcome) welcome.style.display='none';
        var R=window.TeacherAiResources, el;
        if(R){
            el=R.createMessage(turn,{format:format,persist:saveCurrent,copy:function(t){navigator.clipboard&&navigator.clipboard.writeText(t);},regenerate:regenerateResource});
        }else{
            el=document.createElement('article');el.className='teacher-ai-message ai';
            el.innerHTML=format((turn.reply||'')+'\n\n'+((turn.resource&&turn.resource.draft&&turn.resource.draft.content)||''));
            el.taiCreate=function(){return Promise.resolve();};
        }
        body.appendChild(el);body.scrollTop=body.scrollHeight;return el;
    }
    function regenerateResource(id,fmt,title){
        var label=(fmt&&window.TeacherAiResources)?window.TeacherAiResources.formatLabel(fmt)+' ':'';
        send('Regenerate this '+label+'resource with fresh wording.',{regenerate_of:id,output_format:fmt||''});
    }
    function handleResource(data,text){
        var d=data.draft;
        var turn={role:'ai',text:(data.reply||'')+'\n[Generated: '+d.title+' ('+d.type_label+')]',reply:data.reply,resource:{draft:d,cards:[]}};
        current.turns.push(turn);current.draftId=d.draft_id;current.draftInfo={draft_id:d.draft_id,title:d.title,type_label:d.type_label};current.title=data.title||text;
        var el=addResourceMessage(turn);saveCurrent();
        if(data.auto_output) el.taiCreate(data.auto_output);
    }
    function handleConvert(data){
        var info=(current.draftInfo&&current.draftInfo.draft_id===data.draft_id)?current.draftInfo:{draft_id:data.draft_id,title:'Resource',type_label:'Resource'};
        var turn={role:'ai',text:data.reply,reply:data.reply,resource:{draft:info,cards:[],compact:true}};
        current.turns.push(turn);
        var el=addResourceMessage(turn);saveCurrent();
        el.taiCreate(data.auto_output);
    }
    // ---- Student Monitoring dashboard: a data-only card (overview, strong/weak
    // topics, students to monitor, supporting attendance/missing-work info) built
    // from modules/teachers/teacher_ai_chat.php's `mode:"dashboard"` reply. No
    // Gemini call for this card; clicking "Ask Teacher AI to analyze this topic"
    // on a weak topic reuses the normal send() flow instead. ----
    function addDashboardMessage(data){
        if(welcome) welcome.style.display='none';
        var DASH=' — ', NOTE='Not enough data yet to confirm a consistent weakness.';
        var ov=data.overview||{}, monitor=(data.students_to_monitor||[]).slice(), supporting=data.supporting||[];
        // Plain-language names for short assessment codes (display only).
        function niceName(n){
            var s=String(n==null?'':n).trim(), rules=[[/^(?:q|qz|quiz)[\s_-]*(\d+)$/i,'Quiz $1'],[/^pt[\s_-]*(\d+)$/i,'Performance Task $1'],[/^ww[\s_-]*(\d+)$/i,'Written Work $1'],[/^(?:act|activity)[\s_-]*(\d+)$/i,'Activity $1'],[/^st[\s_-]*(\d+)$/i,'Summative Test $1'],[/^lt[\s_-]*(\d+)$/i,'Long Test $1'],[/^term[\s_-]*(\d+)$/i,'Term $1 Assessment'],[/^(?:te|exam)$/i,'Term Exam']];
            for(var i=0;i<rules.length;i++){if(rules[i][0].test(s))return s.replace(rules[i][0],rules[i][1]);}
            return s;
        }
        function cleanLabel(l){return niceName(String(l||'').replace(/\s*\(no Topic tag\)\s*$/i,''));}
        function parseBasis(b){b=String(b||'');var i=b.lastIndexOf(' - ');return i<0?{name:'',score:b}:{name:niceName(b.slice(0,i)),score:b.slice(i+3)};}
        function scoreText(list){var p=(list||[]).map(parseBasis);if(p.length===1)return p[0].score;return p.map(function(x){return x.score+(x.name?' ('+x.name+')':'');}).join(', ');}
        function statusClass(l){return l==='Strong'?'is-good':(l==='Satisfactory'?'is-ok':'is-weak');}
        function plural(n,one,many){return n+' '+(n===1?one:many);}
        var topics=[].concat(
            (data.weak_topics||[]).map(function(t){t.label='Needs Improvement';return t;}),
            (data.satisfactory_topics||[]).map(function(t){t.label='Satisfactory';return t;}),
            (data.strong_topics||[]).map(function(t){t.label='Strong';return t;})
        );
        var el=document.createElement('article');el.className='teacher-ai-message ai tai-dashboard';
        var html='<div class="tai-dash-header"><i class="fas fa-chart-line"></i><strong>'+escapeHtml(data.title||'Student Performance Analytics')+'</strong>'
            +'<span class="tai-dash-sub">'+escapeHtml((data.generated_for||'')+(ov.school_year?' · SY '+ov.school_year:''))+'</span></div>';
        // 1. Performance Overview
        html+='<section class="tai-dash-block"><h4 class="tai-dash-title">Performance Overview</h4><div class="tai-dash-overview">'
            +'<div class="tai-dash-stat"><b>'+(ov.total_students||0)+'</b><span>Total Students</span></div>'
            +'<div class="tai-dash-stat"><b>'+(ov.topics_tracked!=null?ov.topics_tracked:topics.length)+'</b><span>Topics Tracked</span></div>'
            +'<div class="tai-dash-stat"><b>'+(ov.class_average_percent!=null?ov.class_average_percent+'%':'N/A')+'</b><span>Class Average<small>assessment scores</small></span></div>'
            +'<div class="tai-dash-stat'+(monitor.length?' is-attn':'')+'"><b>'+monitor.length+'</b><span>Students to Monitor</span></div>'
            +'</div></section>';
        // 2. Topic Performance (clean cards; 3. details open one at a time)
        html+='<section class="tai-dash-block"><h4 class="tai-dash-title">Topic Performance</h4>';
        if(!topics.length){
            html+='<p class="tai-dash-empty">No topic results yet. Tag assessments with a Topic in Manage Items and record scores to see topic performance.</p>';
        }else{
            html+='<div class="tai-topic-grid">'+topics.map(function(t,i){
                var needing=(t.struggling_students||[]).length, cls=statusClass(t.label);
                var attn=needing?plural(needing,'student needs','students need')+' attention':'No students need attention';
                var items=(t.items||[]).map(function(it){return '<li><span>'+escapeHtml(niceName(it.name))+'</span><b>Class Average: '+it.avg_percent+'%</b></li>';}).join('');
                var studs=(t.struggling_students||[]).map(function(st){return '<li><span>'+escapeHtml(st.student)+'</span><b>'+escapeHtml(scoreText(st.basis))+'</b></li>';}).join('');
                var showNote=(needing||t.label==='Needs Improvement')&&t.enough_data===false;
                var canAsk=needing||t.label==='Needs Improvement';
                return '<div class="tai-tcard '+cls+'">'
                    +'<div class="tai-tcard-head"><span class="tai-tcard-name">'+escapeHtml(t.topic)+'</span><span class="tai-status '+cls+'">'+escapeHtml(t.label)+'</span></div>'
                    +'<div class="tai-tcard-avg"><b>'+t.class_avg_percent+'%</b><span>Topic Average</span></div>'
                    +'<div class="tai-tcard-foot"><span class="tai-tcard-attn'+(needing?' has-attn':'')+'">'+escapeHtml(attn)+'</span>'
                    +'<button type="button" class="tai-view-btn" data-topic-toggle="'+i+'" aria-expanded="false">View Details</button></div>'
                    +'<div class="tai-tcard-details" hidden>'
                    +(items?'<p class="tai-detail-label">Assessments used as basis</p><ul class="tai-detail-list">'+items+'</ul>':'')
                    +'<p class="tai-detail-label">Students needing attention</p>'
                    +(studs?'<ul class="tai-detail-list">'+studs+'</ul>':'<p class="tai-dash-empty">None — all assessed students are at or above the passing mark.</p>')
                    +(showNote?'<p class="tai-data-note"><i class="fas fa-circle-info"></i> '+NOTE+'</p>':'')
                    +(canAsk?'<button type="button" class="tai-analyze-btn" data-topic-index="'+i+'"><i class="fas fa-wand-magic-sparkles"></i> Ask Teacher AI</button>':'')
                    +'</div></div>';
            }).join('')+'</div>';
        }
        html+='</section>';
        // Students to Monitor (topic monitoring only; Academic At-Risk stays in Supporting Information)
        monitor.sort(function(a,b){var ca=/^Consistent/.test(a.monitoring_level||'')?1:0, cb=/^Consistent/.test(b.monitoring_level||'')?1:0;return (cb-ca)||((b.topics||[]).length-(a.topics||[]).length);});
        html+='<section class="tai-dash-block"><h4 class="tai-dash-title">Students to Monitor</h4>'
            +(monitor.length?'<ul class="tai-monitor-list">'+monitor.map(function(s,i){
                var n=(s.topics||[]).length, nMissing=(s.missing||[]).length, close=/^Consistent/.test(s.monitoring_level||'');
                // Each weak topic: exact assessment + score, and an AI Recommendation for this student and topic only.
                var detail=(s.topics||[]).map(function(t,ti){return '<li><span>'+escapeHtml(cleanLabel(t.label))+'</span><b>'+escapeHtml(scoreText(t.basis))+'</b>'
                        +(s.student_id?'<button type="button" class="tai-rec-btn" data-rec-student="'+i+'" data-rec-topic="'+ti+'"><i class="fas fa-wand-magic-sparkles"></i> AI Recommendation</button>':'')+'</li>';}).join('')
                    +(s.missing||[]).map(function(m){return '<li><span>'+escapeHtml(niceName(m.title)+(m.topic?' · '+m.topic:''))+'</span><b class="tai-missing">'+escapeHtml('Missing (deadline '+m.deadline+')')+'</b></li>';}).join('')
                    +(s.declining||[]).map(function(d){var parts=String(d).split(' -- '),m=String(d).match(/earlier avg (\d+)% -> recent avg (\d+)%/);return '<li><span>'+escapeHtml(cleanLabel(parts[0]))+'</span><b>'+escapeHtml('Scores going down'+(m?' ('+m[1]+'% → '+m[2]+'%)':''))+'</b></li>';}).join('');
                return '<li class="tai-monitor-row"><div class="tai-monitor-main"><span class="tai-monitor-name">'+escapeHtml(s.student)+'</span>'
                    +'<span class="tai-monitor-count">'+(n?escapeHtml(plural(n,'weak topic','weak topics')):(nMissing?escapeHtml(plural(nMissing,'missing task','missing tasks')):'Declining trend'))+'</span>'
                    +'<span class="tai-status '+(close?'is-weak':'is-ok')+'">'+(close?'Monitor Closely':'Continue Monitoring')+'</span>'
                    +'<button type="button" class="tai-view-btn" data-student-toggle="'+i+'" aria-expanded="false">View Student</button></div>'
                    +'<div class="tai-monitor-details" hidden>'
                    +(s.priority_topic?'<p class="tai-priority"><i class="fas fa-triangle-exclamation"></i> Priority Weak Topic: <b>'+escapeHtml(s.priority_topic.topic)+'</b> <span>'+escapeHtml(scoreText(s.priority_topic.basis))+'</span></p>':'')
                    +((s.assessments||[]).length?'<table class="tai-src-table"><thead><tr><th>Source</th><th>Assessment</th><th>Topic</th><th>Score</th></tr></thead><tbody>'+s.assessments.map(function(a){return '<tr'+(a.low?' class="is-low"':'')+'><td>'+escapeHtml(a.source)+'</td><td>'+escapeHtml(niceName(a.assessment))+'</td><td>'+escapeHtml(a.topic)+'</td><td>'+escapeHtml(a.score)+'</td></tr>';}).join('')+'</tbody></table>':'')
                    +(detail?'<ul class="tai-detail-list">'+detail+'</ul>':'<p class="tai-dash-empty">No topic details available.</p>')
                    +(close||!n?'':'<p class="tai-data-note"><i class="fas fa-circle-info"></i> '+NOTE+'</p>')+'</div></li>';
            }).join('')+'</ul>'
                :'<p class="tai-dash-empty">No students currently need topic monitoring.</p>')
            +'</section>';
        // Supporting Information (collapsed)
        html+='<details class="tai-dash-supporting"><summary>Supporting Information <small>attendance, missing work, Academic At-Risk status</small></summary>'
            +(supporting.length?'<ul class="tai-detail-list tai-supporting-list">'+supporting.map(function(s){return '<li><span>'+escapeHtml(s.student)+'</span><b>'+escapeHtml(s.attendance+' · missing entries: '+s.missing_grade_entries+' · '+s.risk_label)+'</b></li>';}).join('')+'</ul>'
                :'<p class="tai-dash-empty">No supporting records found.</p>')
            +'</details>';
        el.innerHTML=html;
        // One open panel at a time within each list.
        function bindToggles(attr,panelSel,rowSel,openText,closeText){
            var btns=el.querySelectorAll('['+attr+']');
            btns.forEach(function(btn){
                btn.onclick=function(){
                    var row=btn.closest(rowSel), panel=row.querySelector(panelSel), opening=panel.hidden;
                    btns.forEach(function(o){var r=o.closest(rowSel);r.querySelector(panelSel).hidden=true;r.classList.remove('is-open');o.textContent=openText;o.setAttribute('aria-expanded','false');});
                    if(opening){panel.hidden=false;row.classList.add('is-open');btn.textContent=closeText;btn.setAttribute('aria-expanded','true');}
                };
            });
        }
        bindToggles('data-topic-toggle','.tai-tcard-details','.tai-tcard','View Details','Hide Details');
        bindToggles('data-student-toggle','.tai-monitor-details','.tai-monitor-row','View Student','Hide Student');
        el.querySelectorAll('.tai-rec-btn').forEach(function(btn){
            btn.onclick=function(){
                var s=monitor[parseInt(btn.getAttribute('data-rec-student'),10)], t=s&&(s.topics||[])[parseInt(btn.getAttribute('data-rec-topic'),10)];if(!t)return;
                var raw=String(t.label||'').replace(/\s*\(no Topic tag\)\s*$/i,'');
                send('AI Recommendation for '+s.student+' on the weak topic "'+cleanLabel(t.label)+'": a short review and practice for this topic only.',{focus_topic:raw,focus_student_id:s.student_id});
            };
        });
        el.querySelectorAll('.tai-analyze-btn').forEach(function(btn){
            btn.onclick=function(){
                var t=topics[parseInt(btn.getAttribute('data-topic-index'),10)];if(!t)return;
                send('Analyze the class\'s performance in the topic "'+t.topic+'" using only the listed assessment scores, and give short recommendations related only to this topic.',{focus_topic:t.topic});
            };
        });
        body.appendChild(el);body.scrollTop=body.scrollHeight;return el;
    }
    // ---- "Send review to ..." under an AI Recommendation (one student) or a
    // topic's "Ask Teacher AI" analysis (its students needing attention).
    // Pre-filled with the answer's "Message to Student(s)" section; the
    // teacher can edit it and untick students, and nothing is sent until
    // they click Send. ----
    function addReviewPanel(afterEl,reply,target){
        if(!afterEl||!target)return;
        var list=target.students||(target.student_id?[{student_id:target.student_id,student:target.student}]:[]);
        if(!list.length)return;
        var many=!!target.students;
        var text=String(reply||''), m=text.match(/message to students?\**\s*:?\**\s*([\s\S]+)$/i);
        text=(m?m[1]:text.replace(/^.*\b(student|next monitoring|class average|basis)\s*:.*$/gim,'')).replace(/\*\*/g,'').replace(/^#+\s*/gm,'').replace(/\n{3,}/g,'\n\n').trim();
        var box=document.createElement('div');box.className='tai-review-send';
        box.innerHTML='<div class="tai-review-title"><i class="fas fa-paper-plane"></i> <span></span></div>'
            +'<p class="tai-review-hint">This goes to each student\'s subject page in the Student Portal so they can review before your next assessment. You can edit it first.</p>'
            +(many?'<div class="tai-review-students"></div>':'')
            +'<textarea class="tai-input" rows="9" maxlength="6000"></textarea>'
            +'<div class="tai-review-actions"><button type="button" class="tai-btn tai-btn-primary"></button><span class="tai-review-status" role="status"></span></div>';
        box.querySelector('.tai-review-title span').textContent=many?('Message to students needing attention in "'+(target.topic||'')+'"'):('Send review to '+list[0].student);
        var picks=box.querySelector('.tai-review-students');
        if(picks){list.forEach(function(st){var l=document.createElement('label');l.innerHTML='<input type="checkbox" checked> <span></span>';l.querySelector('input').value=st.student_id;l.querySelector('span').textContent=st.student;picks.appendChild(l);});}
        var ta=box.querySelector('textarea'), btn=box.querySelector('button'), st=box.querySelector('.tai-review-status');
        function chosen(){return picks?[].slice.call(picks.querySelectorAll('input:checked')).map(function(i){return parseInt(i.value,10);}):[list[0].student_id];}
        function label(){var n=chosen().length;btn.textContent=many?('Send review to '+n+' student'+(n===1?'':'s')):('Send review to '+list[0].student);btn.disabled=n===0;}
        if(picks)picks.addEventListener('change',label);
        ta.value=text;label();
        btn.onclick=function(){
            var ids=chosen();
            if(!ids.length){st.textContent='Choose at least one student.';return;}
            if(!ta.value.trim()){st.textContent='The review is empty.';return;}
            btn.disabled=true;st.textContent='Sending…';
            fetch(root.dataset.endpoint.replace(/teacher_ai_chat\.php$/,'send_review_to_student.php'),{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':root.dataset.csrf},credentials:'same-origin',
                body:JSON.stringify({csrf_token:root.dataset.csrf,student_ids:ids,subject_code:target.subject_code,topic:target.topic,message:ta.value.trim()})})
                .then(function(r){return r.json();}).then(function(d){
                    if(d&&d.success){st.innerHTML='<i class="fas fa-circle-check"></i> Sent to '+(d.sent||ids.length)+' student'+((d.sent||ids.length)===1?'':'s')+'. They will see it on their subject page.';ta.readOnly=true;btn.textContent='Sent';if(picks)picks.querySelectorAll('input').forEach(function(i){i.disabled=true;});}
                    else{btn.disabled=false;st.textContent=(d&&d.error)||'Could not send. Please try again.';}
                }).catch(function(){btn.disabled=false;st.textContent='Could not send. Please try again.';});
        };
        afterEl.appendChild(box);body.scrollTop=body.scrollHeight;
    }
    function handleDashboard(data){
        var turn={role:'ai',text:'[Student Monitoring dashboard: '+(data.generated_for||'')+']',dashboard:data};
        current.turns.push(turn);current.title=data.title||current.title;
        addDashboardMessage(data);saveCurrent();
    }
    function sendDashboard(action){
        if(busySending)return;
        closePlusMenu();
        if(welcome) welcome.style.display='none';
        var label='Student Monitoring dashboard'+(current.subject?(' for '+current.subject.name):'');
        var userTurn={role:'user',text:'Show my '+label+'.'};
        current.turns.push(userTurn);addMessage('user',userTurn.text);
        var typing=addMessage('ai','Loading dashboard…');typing.classList.add('teacher-ai-typing');
        busySending=true;sendBtn.disabled=true;
        var payload={csrf_token:root.dataset.csrf,dashboard:action};
        if(current.subject&&current.subject.code)payload.subject_code=current.subject.code;
        fetch(root.dataset.endpoint,{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':root.dataset.csrf},credentials:'same-origin',body:JSON.stringify(payload)})
            .then(function(r){return r.json();}).then(function(data){
                typing.remove();
                if(!data.success){addMessage('ai',data.error||'Teacher AI is unavailable.');return;}
                if(data.mode==='dashboard'){handleDashboard(data);return;}
                addMessage('ai',data.reply||'Teacher AI is unavailable.',true);
            }).catch(function(){typing.remove();addMessage('ai','Teacher AI is temporarily unavailable.');}).finally(function(){busySending=false;sendBtn.disabled=false;});
    }
    // ---- "+" button: attach a file (image / PDF / Word / text) or start a common
    // teacher task. Files go to the server with the next message only (never stored). ----
    var plusBtn=document.getElementById('teacherAiPlus'), plusMenu=document.getElementById('teacherAiPlusMenu'), fileInput=document.getElementById('teacherAiFile'), attachRow=document.getElementById('teacherAiAttachRow');
    var pendingAttachment=null, busySending=false, attachThumbUrl='', ATTACH_MAX=4*1024*1024, ATTACH_EXT=/\.(png|jpe?g|webp|pdf|docx|txt|md|csv)$/i;
    function fileSizeLabel(n){return n>=1048576?(n/1048576).toFixed(1)+' MB':Math.max(1,Math.round(n/1024))+' KB';}
    function addAttachmentNote(el,name){if(!el)return;var n=document.createElement('div');n.className='tai-msg-attach';n.innerHTML='<i class="fas fa-paperclip"></i><span>'+escapeHtml(name)+'</span>';el.appendChild(n);}
    function readAttachment(file){return new Promise(function(resolve,reject){var fr=new FileReader();fr.onload=function(){var r=String(fr.result||'');resolve({name:file.name,mime_type:file.type||'',data:r.slice(r.indexOf(',')+1)});};fr.onerror=function(){reject(fr.error);};fr.readAsDataURL(file);});}
    function clearAttachment(){pendingAttachment=null;if(attachThumbUrl){try{URL.revokeObjectURL(attachThumbUrl);}catch(e){}attachThumbUrl='';}renderAttachment();if(fileInput)fileInput.value='';}
    function attachmentError(msg){attachRow.hidden=false;attachRow.innerHTML='<span class="tai-attach-error"><i class="fas fa-circle-exclamation"></i> '+escapeHtml(msg)+'</span>';setTimeout(function(){if(!pendingAttachment)renderAttachment();},4500);}
    function renderAttachment(){
        if(!pendingAttachment){attachRow.hidden=true;attachRow.innerHTML='';return;}
        var f=pendingAttachment.file,isImg=/^image\//.test(f.type);
        attachRow.hidden=false;
        attachRow.innerHTML='<span class="tai-attach-chip"><span class="tai-attach-thumb">'+(isImg&&attachThumbUrl?'<img alt="" src="'+attachThumbUrl+'">':'<i class="fas '+(/\.pdf$/i.test(f.name)?'fa-file-pdf':(/\.docx$/i.test(f.name)?'fa-file-word':'fa-file-lines'))+'"></i>')+'</span><span class="tai-attach-name" title="'+escapeHtml(f.name)+'">'+escapeHtml(f.name)+'</span><span class="tai-attach-size">'+fileSizeLabel(f.size)+'</span><button type="button" aria-label="Remove attachment" title="Remove"><i class="fas fa-xmark"></i></button></span>';
        attachRow.querySelector('button').onclick=function(){clearAttachment();input.focus();};
    }
    function setAttachment(file){
        if(!file)return;
        if(!ATTACH_EXT.test(file.name)){attachmentError('That file type is not supported. Use an image, PDF, Word (.docx) or text file.');return;}
        if(file.size>ATTACH_MAX){attachmentError('That file is too large. Please choose one under 4 MB.');return;}
        if(file.size<=0){attachmentError('That file is empty.');return;}
        if(attachThumbUrl){try{URL.revokeObjectURL(attachThumbUrl);}catch(e){}attachThumbUrl='';}
        if(/^image\//.test(file.type)){try{attachThumbUrl=URL.createObjectURL(file);}catch(e){attachThumbUrl='';}}
        pendingAttachment={file:file};renderAttachment();input.focus();
    }
    function plusMenuOpen(){return plusMenu&&!plusMenu.hidden;}
    function openPlusMenu(){if(!plusMenu)return;plusMenu.hidden=false;plusBtn.setAttribute('aria-expanded','true');var first=plusMenu.querySelector('button');if(first)first.focus();}
    function closePlusMenu(){if(!plusMenu||plusMenu.hidden)return;plusMenu.hidden=true;plusBtn.setAttribute('aria-expanded','false');}
    // Starters are pre-filled (not sent) so the teacher can finish the sentence with the topic.
    function starterText(kind){
        var n=current.subject?current.subject.name:'';
        var f=function(withSubject,without){return n?withSubject.replace('{S}',n):without;};
        var t={
            lesson:f('Create a lesson plan for {S} about ','Create a lesson plan about '),
            quiz:f('Create a quiz for {S} about ','Create a quiz about '),
            activity:f('Create a classroom activity for {S} about ','Create a classroom activity about '),
            task:f('Create a performance task with a rubric for {S} about ','Create a performance task with a rubric about '),
            slides:f('Create a PowerPoint presentation for {S} about ','Create a PowerPoint presentation about '),
            explain:f('Explain in simple terms, for my {S} class: ','Explain in simple terms: ')
        };
        return t[kind]||'';
    }
    function sendStarter(kind){
        var n=current.subject?current.subject.name:'';
        if(kind==='monitor')return send(n?'Which of my '+n+' students are missing work or at risk, and what is each student\'s attendance out of recorded sessions?':'Which of my students are inactive, missing work or at risk, and what is each student\'s attendance out of recorded sessions?');
        if(kind==='grading')return send('How are Written/Oral Works, Performance Tasks and Examinations computed into the Term Grade in E-GRADEPRO under DO 015, s. 2026?');
    }
    if(plusBtn&&plusMenu){
        plusBtn.addEventListener('click',function(e){e.preventDefault();e.stopPropagation();if(plusMenuOpen())closePlusMenu();else openPlusMenu();});
        plusMenu.addEventListener('click',function(e){
            var b=e.target.closest?e.target.closest('button'):null;if(!b)return;e.preventDefault();
            closePlusMenu();
            if(b.hasAttribute('data-tai-attach')){fileInput.click();return;}
            if(b.getAttribute('data-tai-prefill')){setDraft(starterText(b.getAttribute('data-tai-prefill')));return;}
            if(b.getAttribute('data-tai-send')){sendStarter(b.getAttribute('data-tai-send'));}
        });
        plusMenu.addEventListener('keydown',function(e){
            var items=Array.prototype.slice.call(plusMenu.querySelectorAll('button')),i=items.indexOf(document.activeElement);
            if(e.key==='ArrowDown'){e.preventDefault();items[(i+1)%items.length].focus();}
            else if(e.key==='ArrowUp'){e.preventDefault();items[(i-1+items.length)%items.length].focus();}
        });
        document.addEventListener('click',function(e){if(plusMenuOpen()&&!plusMenu.contains(e.target)&&e.target!==plusBtn&&!plusBtn.contains(e.target))closePlusMenu();});
        // Esc closes the menu first (capture phase, so it never also closes the whole drawer).
        document.addEventListener('keydown',function(e){if(e.key==='Escape'&&plusMenuOpen()){e.stopImmediatePropagation();e.preventDefault();closePlusMenu();plusBtn.focus();}},true);
        fileInput.addEventListener('change',function(){setAttachment(fileInput.files&&fileInput.files[0]);});
        // Pasting a screenshot / image into the box attaches it too.
        input.addEventListener('paste',function(e){var items=e.clipboardData&&e.clipboardData.files;if(items&&items.length&&/^image\//.test(items[0].type)){e.preventDefault();setAttachment(items[0]);}});
    }
    function send(text,opts){
        opts=opts||{};
        var att=opts.regenerate_of?null:pendingAttachment;
        text=(text||input.value).trim();if(!text&&att)text='Please review the attached file.';if(!text)return;
        if(busySending)return;
        var exportFormat=requestedExportFormat(text);
        input.value='';input.style.height='auto';
        closePlusMenu();
        var userTurn={role:'user',text:text};if(att)userTurn.attachment_name=att.file.name;
        current.turns.push(userTurn);var userEl=addMessage('user',text);if(att)addAttachmentNote(userEl,att.file.name);
        var typing=addMessage('ai','Thinking…');typing.classList.add('teacher-ai-typing');
        busySending=true;sendBtn.disabled=true;
        var payload={csrf_token:root.dataset.csrf,message:text,history:turnsForServer(),draft_id:current.draftId||0};
        if(current.subject&&current.subject.code)payload.subject_code=current.subject.code;
        if(opts.regenerate_of)payload.regenerate_of=opts.regenerate_of;
        if(opts.output_format)payload.output_format=opts.output_format;
        if(opts.focus_topic)payload.focus_topic=opts.focus_topic;
        if(opts.focus_student_id)payload.focus_student_id=opts.focus_student_id;
        // "<item> AI" chat (Manage Assessment Items -> Send to <item> AI): every
        // message in it stays about that item's attached lesson.
        if(current.assessment&&current.assessment.item_id){payload.assessment_item_id=current.assessment.item_id;if(opts.assessment_start)payload.assessment_start=1;}
        // The file is sent with this one message only; the chip clears right away.
        var attachmentReady=att?readAttachment(att.file):Promise.resolve(null);
        if(att)clearAttachment();
        attachmentReady.then(function(a){if(a)payload.attachment=a;return fetch(root.dataset.endpoint,{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':root.dataset.csrf},credentials:'same-origin',body:JSON.stringify(payload)});}).then(function(r){return r.json();}).then(function(data){
            typing.remove();
            if(!data.success){addMessage('ai',data.error||'Teacher AI is unavailable.');return;}
            if(data.mode==='resource'&&data.draft){handleResource(data,text);return;}
            if(data.mode==='convert'&&data.draft_id){handleConvert(data);return;}
            current.turns.push({role:'ai',text:data.reply});current.title=data.title||text;var replyEl=addMessage('ai',data.reply,true,exportFormat);if(data.review_target)addReviewPanel(replyEl,data.reply,data.review_target);saveCurrent();
        }).catch(function(){typing.remove();addMessage('ai','Teacher AI is temporarily unavailable.');}).finally(function(){busySending=false;sendBtn.disabled=false;});
    }
    document.getElementById('teacherAiLauncher').onclick=open;document.getElementById('teacherAiClose').onclick=close;document.getElementById('teacherAiScrim').onclick=close;document.getElementById('teacherAiMinimize').onclick=close;document.getElementById('teacherAiHistory').onclick=function(){historyPanel.hidden=!historyPanel.hidden;renderHistory();};document.getElementById('teacherAiNewChat').onclick=newChat;form.addEventListener('submit',function(e){e.preventDefault();send();});input.addEventListener('keydown',function(e){if(e.key==='Enter'&&!e.shiftKey){e.preventDefault();send();}});input.addEventListener('input',function(){this.style.height='auto';this.style.height=Math.min(this.scrollHeight,100)+'px';});bindWelcomeOptions();document.addEventListener('keydown',function(e){if(e.key==='Escape'&&root.classList.contains('is-open'))close();});
    // Opened from Manage Assessment Items ("Send to <item> AI" / "Open <item> AI"):
    // a new chat tied to that assessment item, which immediately prepares the
    // assessment from the attached lesson (see teacher_ai_chat.php, assessment mode).
    window.TeacherAI={startAssessment:function(itemId,label){
        itemId=parseInt(itemId,10);if(!itemId)return;
        if(busySending){open();return;}
        newChat();
        current.assessment={item_id:itemId,label:String(label||'')};
        current.title=String(label||'Assessment AI');
        open();
        send('Prepare '+(label||'this assessment')+' from the attached lesson.',{assessment_start:true});
    }};
})();
