/* static/js/file-relevance.js
 * Warning-only "does this file match the subject?" notice for teacher uploads.
 *   FileRelevance.attach({ input, form, url, csrf, getSubject, getContext })
 * Never blocks: if the check fails or the AI is busy, nothing is shown. When a warning IS
 * shown, submitting the form asks once "Ipagpatuloy pa rin?" and the teacher can go on. */
(function () {
    var MSG = 'Mukhang hindi tugma sa subject ang file na ito. Ipagpatuloy pa rin ang pag-upload?';

    function attach(o) {
        if (!o || !o.input || !o.url) return;
        var input = o.input, form = o.form || input.form;
        var box = document.createElement('div');
        box.className = 'frc-note';
        box.style.cssText = 'display:none;margin-top:6px;font-size:14px;line-height:1.4;border-radius:8px;padding:8px 10px;';
        var anchor = input.parentNode && input.parentNode.classList && input.parentNode.classList.contains('mai-attach') ? input.parentNode : input;
        anchor.insertAdjacentElement('afterend', box);
        var warned = false, confirmed = false, seq = 0;

        var STYLES = {
            related:   ['#ECFDF3', '#166534', '#A7E3BC'],
            unrelated: ['#FFF4E5', '#8A4B08', '#F5C58A'],
            unknown:   ['#F1F5F9', '#475569', '#E2E8F0'],
            loading:   ['#F1F5F9', '#475569', '#E2E8F0']
        };
        function show(html, kind) {
            var st = STYLES[kind] || STYLES.unknown;
            box.style.display = html ? 'block' : 'none';
            box.style.background = st[0];
            box.style.color = st[1];
            box.style.border = '1px solid ' + st[2];
            box.innerHTML = html;
        }
        function esc(s) { var d = document.createElement('div'); d.textContent = s; return d.innerHTML; }

        input.addEventListener('change', function () {
            var my = ++seq;
            warned = false; confirmed = false;
            if (!input.files || !input.files.length) { show('', 'unknown'); return; }
            var subject = (o.getSubject ? o.getSubject() : '') || '';
            if (!subject) { show('', 'unknown'); return; }
            show('<i class="fas fa-spinner fa-spin me-1"></i>Sinusuri ng AI kung tugma ang file sa subject…', 'loading');
            var fd = new FormData();
            fd.append('csrf_token', o.csrf || '');
            fd.append('subject_name', subject);
            fd.append('context', (o.getContext ? o.getContext() : '') || '');
            Array.prototype.forEach.call(input.files, function (f) { fd.append('files[]', f, f.name); });
            fetch(o.url, { method: 'POST', body: fd, credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    if (my !== seq) return;   // a newer file choice replaced this one
                    var res = (d && d.results) || [];
                    if (!res.length) { show('Hindi nasuri ang file. Puwede mo pa ring i-upload.', 'unknown'); return; }
                    var lines = [], hasWarn = false, allRelated = true;
                    res.forEach(function (x) {
                        var r = x.reason ? ' — ' + esc(x.reason) : '';
                        if (x.status === 'related') {
                            lines.push('<i class="fas fa-circle-check me-1"></i><strong>Tugma sa subject:</strong> ' + esc(x.name) + r);
                        } else if (x.status === 'unrelated') {
                            hasWarn = true; allRelated = false;
                            lines.push('<i class="fas fa-triangle-exclamation me-1"></i><strong>Baka hindi tugma sa subject:</strong> ' + esc(x.name) + r);
                        } else {
                            allRelated = false;
                            lines.push('<i class="fas fa-circle-question me-1"></i><strong>Hindi nasuri:</strong> ' + esc(x.name) + r);
                        }
                    });
                    warned = hasWarn;
                    var html = lines.join('<br>');
                    if (hasWarn) html += '<br><small>Babala lang ito. Puwede mo pa ring i-upload.</small>';
                    show(html, hasWarn ? 'unrelated' : (allRelated ? 'related' : 'unknown'));
                })
                .catch(function () { if (my === seq) show('Hindi nakonekta ang pagsusuri. Puwede mo pa ring i-upload.', 'unknown'); });   // never block on a failed check
        });

        if (form) {
            form.addEventListener('submit', function (e) {
                if (!warned || confirmed || !input.files || !input.files.length) return;
                e.preventDefault();
                if (window.confirm(MSG)) {
                    confirmed = true;
                    if (form.requestSubmit) { form.requestSubmit(e.submitter || undefined); } else { form.submit(); }
                }
            });
        }
    }
    window.FileRelevance = { attach: attach };
})();