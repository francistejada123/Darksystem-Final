// static/js/dropdown-below.js
// A dropdown list that always opens BELOW its field. The browser's own list
// opens upward when there is little room under the field. The real <select>
// stays in the page (hidden) and keeps the value, its change handlers and
// form submission; this only draws the list from its current options and
// optgroups every time it opens (so lists rebuilt by other scripts work too).
//
//   DropdownBelow.enhance(document.getElementById('mySelect'));
(function () {
    var CSS = ''
        + '.ddb{position:relative}'
        + '.ddb>select{display:none!important}'
        + '.ddb-btn{width:100%;display:flex;align-items:center;justify-content:space-between;gap:8px;text-align:left;cursor:pointer;background-color:var(--surface,#fff);color:var(--ink,#14213D);background-image:none!important}'
        + '.ddb-btn span{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}'
        + '.ddb-btn i{font-size:12px;color:var(--text-muted,#667085);transition:transform .15s;flex:0 0 auto}'
        + '.ddb.open .ddb-btn{border-color:var(--primary,#5B5FEF);box-shadow:0 0 0 2px rgba(91,95,239,.12)}'
        + '.ddb.open .ddb-btn i{transform:rotate(180deg)}'
        + '.ddb-list{display:none;position:absolute;left:0;top:calc(100% + 4px);min-width:100%;width:max-content;max-width:min(420px,90vw);z-index:1060;max-height:320px;overflow-y:auto;background:var(--surface,#fff);border:1px solid var(--border,#E4E7EC);border-radius:10px;box-shadow:0 12px 28px rgba(16,24,40,.16);padding:6px 0}'
        + '.ddb.open .ddb-list{display:block}'
        + '.ddb-group{padding:8px 14px 4px;font-size:12.5px;font-weight:800;letter-spacing:.03em;text-transform:uppercase;color:var(--text-muted,#667085);position:sticky;top:-6px;background:var(--surface,#fff)}'
        + '.ddb-opt{display:block;width:100%;border:0;background:transparent;text-align:left;padding:8px 14px;font-size:15px;color:var(--ink,#14213D);cursor:pointer;white-space:nowrap}'
        + '.ddb-opt.in-group{padding-left:26px}'
        + '.ddb-opt:hover,.ddb-opt.focus{background:var(--primary-soft,#EEF1FE)}'
        + '.ddb-opt.selected{background:var(--primary,#5B5FEF);color:#fff}';
    function addCss() {
        if (document.getElementById('ddbCss')) return;
        var st = document.createElement('style');
        st.id = 'ddbCss';
        st.textContent = CSS;
        document.head.appendChild(st);
    }
    function esc(t) { var d = document.createElement('div'); d.textContent = t == null ? '' : t; return d.innerHTML; }

    function enhance(select) {
        if (!select || select.dataset.ddb) return;
        select.dataset.ddb = '1';
        addCss();
        var wrap = document.createElement('div');
        wrap.className = 'ddb';
        select.parentNode.insertBefore(wrap, select);
        wrap.appendChild(select);
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = (select.className || '') + ' ddb-btn';
        btn.setAttribute('aria-haspopup', 'listbox');
        btn.setAttribute('aria-expanded', 'false');
        var list = document.createElement('div');
        list.className = 'ddb-list';
        list.setAttribute('role', 'listbox');
        wrap.appendChild(btn);
        wrap.appendChild(list);

        function label() {
            var o = select.options[select.selectedIndex];
            btn.innerHTML = '<span>' + esc(o ? o.text.trim() : '') + '</span><i class="fas fa-chevron-down" aria-hidden="true"></i>';
        }
        function optHtml(o, inGroup) {
            if (o.hidden || o.disabled) return '';
            return '<button type="button" role="option" class="ddb-opt' + (inGroup ? ' in-group' : '') + (o.selected ? ' selected' : '')
                + '" data-value="' + esc(o.value) + '">' + esc(o.text.trim()) + '</button>';
        }
        function draw() {
            var html = '';
            Array.prototype.forEach.call(select.children, function (el) {
                if (el.tagName === 'OPTION') html += optHtml(el, false);
                else if (el.tagName === 'OPTGROUP' && !el.hidden) {
                    var inner = '';
                    Array.prototype.forEach.call(el.children, function (o) { inner += optHtml(o, true); });
                    if (inner) html += '<div class="ddb-group">' + esc(el.label) + '</div>' + inner;
                }
            });
            list.innerHTML = html;
        }
        function open() {
            draw();
            wrap.classList.add('open');
            btn.setAttribute('aria-expanded', 'true');
            var sel = list.querySelector('.selected');
            if (sel) list.scrollTop = Math.max(0, sel.offsetTop - 40);
            // If the list would end below the window, scroll the page just enough to show it.
            var r = list.getBoundingClientRect();
            if (r.bottom > window.innerHeight - 8) window.scrollBy({ top: r.bottom - window.innerHeight + 16, behavior: 'smooth' });
            // Keep it inside the window horizontally.
            list.style.left = '0'; list.style.right = 'auto';
            if (list.getBoundingClientRect().right > window.innerWidth - 8) { list.style.left = 'auto'; list.style.right = '0'; }
        }
        function close() { wrap.classList.remove('open'); btn.setAttribute('aria-expanded', 'false'); }
        function choose(v) {
            close();
            if (select.value !== v) {
                select.value = v;
                select.dispatchEvent(new Event('change', { bubbles: true }));   // also runs inline onchange="..."
            }
            label();
            btn.focus();
        }
        btn.addEventListener('click', function () { wrap.classList.contains('open') ? close() : open(); });
        list.addEventListener('click', function (e) {
            var o = e.target.closest('.ddb-opt');
            if (o) choose(o.getAttribute('data-value'));
        });
        document.addEventListener('click', function (e) { if (!wrap.contains(e.target)) close(); });
        wrap.addEventListener('keydown', function (e) {
            var opts = Array.prototype.slice.call(list.querySelectorAll('.ddb-opt'));
            var i = opts.indexOf(list.querySelector('.ddb-opt.focus'));
            if (e.key === 'Escape') { close(); btn.focus(); return; }
            if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
                e.preventDefault();
                if (!wrap.classList.contains('open')) { open(); return; }
                if (i < 0) i = opts.indexOf(list.querySelector('.ddb-opt.selected'));
                i = e.key === 'ArrowDown' ? Math.min(opts.length - 1, i + 1) : Math.max(0, i - 1);
                opts.forEach(function (o) { o.classList.remove('focus'); });
                if (opts[i]) { opts[i].classList.add('focus'); opts[i].scrollIntoView({ block: 'nearest' }); }
            } else if (e.key === 'Enter' && wrap.classList.contains('open') && i >= 0) {
                e.preventDefault();
                choose(opts[i].getAttribute('data-value'));
            }
        });
        // Value or options changed by other scripts (e.g. dependent lists, Clear Filters).
        select.addEventListener('change', label);
        if (window.MutationObserver) new MutationObserver(label).observe(select, { childList: true, subtree: true });
        var desc = Object.getOwnPropertyDescriptor(HTMLSelectElement.prototype, 'value');
        if (desc && desc.set) {
            Object.defineProperty(select, 'value', {
                configurable: true,
                get: function () { return desc.get.call(select); },
                set: function (v) { desc.set.call(select, v); label(); }
            });
        }
        label();
    }
    window.DropdownBelow = { enhance: enhance };
})();
