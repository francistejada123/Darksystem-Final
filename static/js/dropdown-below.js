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
        + '.ddb-list{display:none;position:fixed;left:0;top:0;width:max-content;max-width:min(420px,90vw);z-index:2000;max-height:320px;overflow-y:auto;background:var(--surface,#fff);border:1px solid var(--border,#E4E7EC);border-radius:10px;box-shadow:0 12px 28px rgba(16,24,40,.16);padding:6px 0}'
        + '.ddb-list.open{display:block}'
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
        // The list lives on <body> (position: fixed), so a card with overflow
        // or an animated section below can never cover or cut it.
        document.body.appendChild(list);

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
        function place() {
            var b = btn.getBoundingClientRect();
            list.style.minWidth = b.width + 'px';
            list.style.top = (b.bottom + 4) + 'px';
            list.style.maxHeight = Math.max(120, Math.min(320, window.innerHeight - b.bottom - 16)) + 'px';
            var w = list.offsetWidth;
            list.style.left = Math.max(8, Math.min(b.left, window.innerWidth - w - 8)) + 'px';
        }
        function isOpen() { return list.classList.contains('open'); }
        function open() {
            draw();
            // Too little room below the field: scroll the page up a bit first, then open below.
            var room = window.innerHeight - btn.getBoundingClientRect().bottom;
            if (room < 220) window.scrollBy({ top: 220 - room });
            wrap.classList.add('open');
            list.classList.add('open');
            btn.setAttribute('aria-expanded', 'true');
            place();
            var sel = list.querySelector('.selected');
            if (sel) list.scrollTop = Math.max(0, sel.offsetTop - 40);
        }
        function close() { wrap.classList.remove('open'); list.classList.remove('open'); btn.setAttribute('aria-expanded', 'false'); }
        window.addEventListener('scroll', function () { if (isOpen()) place(); }, true);
        window.addEventListener('resize', function () { if (isOpen()) place(); });
        function choose(v) {
            close();
            if (select.value !== v) {
                select.value = v;
                select.dispatchEvent(new Event('change', { bubbles: true }));   // also runs inline onchange="..."
            }
            label();
            btn.focus();
        }
        btn.addEventListener('click', function () { isOpen() ? close() : open(); });
        list.addEventListener('click', function (e) {
            var o = e.target.closest('.ddb-opt');
            if (o) choose(o.getAttribute('data-value'));
        });
        document.addEventListener('click', function (e) { if (!wrap.contains(e.target) && !list.contains(e.target)) close(); });
        wrap.addEventListener('keydown', function (e) {
            var opts = Array.prototype.slice.call(list.querySelectorAll('.ddb-opt'));
            var i = opts.indexOf(list.querySelector('.ddb-opt.focus'));
            if (e.key === 'Escape') { close(); btn.focus(); return; }
            if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
                e.preventDefault();
                if (!isOpen()) { open(); return; }
                if (i < 0) i = opts.indexOf(list.querySelector('.ddb-opt.selected'));
                i = e.key === 'ArrowDown' ? Math.min(opts.length - 1, i + 1) : Math.max(0, i - 1);
                opts.forEach(function (o) { o.classList.remove('focus'); });
                if (opts[i]) { opts[i].classList.add('focus'); opts[i].scrollIntoView({ block: 'nearest' }); }
            } else if (e.key === 'Enter' && isOpen() && i >= 0) {
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
