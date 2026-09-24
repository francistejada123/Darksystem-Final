(function () {
    'use strict';
    var root = document.getElementById('teacherAiRoot');
    var body = document.getElementById('teacherAiBody');
    if (!root || !body) return;

    function downloadResource(button, format, content, title) {
        button.disabled = true;
        var form = new FormData();
        form.append('csrf_token', root.dataset.csrf || '');
        form.append('format', format);
        form.append('title', title);
        form.append('content', content);
        fetch(root.dataset.fileEndpoint, { method: 'POST', credentials: 'same-origin', body: form })
            .then(function (response) {
                if (!response.ok) return response.text().then(function (message) { throw new Error(message || 'Could not create the file.'); });
                return response.blob();
            })
            .then(function (blob) {
                var url = URL.createObjectURL(blob);
                var link = document.createElement('a');
                link.href = url;
                var ext = format === 'ppt' ? 'pptx' : format;
                link.download = title.replace(/[^a-z0-9]+/gi, '_').toLowerCase() + '.' + ext;
                link.click();
                URL.revokeObjectURL(url);
            })
            .catch(function (error) { window.alert(error.message || 'Could not create the file.'); })
            .finally(function () { button.disabled = false; });
    }

    function enhance(message) {
        if (message.dataset.exportsReady === '1') return;
        if (!message.dataset.exportFormat) return;
        message.dataset.exportsReady = '1';
        var text = message.innerText.replace(/\s+(Copy|Regenerate)$/g, '').trim();
        if (!text) return;
        var heading = message.querySelector('h3');
        var title = heading ? heading.textContent.trim() : 'E-GRADEPRO Teacher AI Resource';
        var actions = message.querySelector('.teacher-ai-message-actions');
        if (!actions) {
            actions = document.createElement('div');
            actions.className = 'teacher-ai-message-actions';
            message.appendChild(actions);
        }
        var requestedFormat = message.dataset.exportFormat;
        [[requestedFormat, requestedFormat === 'pdf' ? 'fa-file-pdf' : 'fa-file-powerpoint', requestedFormat.toUpperCase()]].forEach(function (item) {
            var button = document.createElement('button');
            button.type = 'button';
            button.innerHTML = '<i class="fas ' + item[1] + '"></i> ' + item[2];
            button.title = 'Generate ' + item[2];
            button.addEventListener('click', function () { downloadResource(button, item[0], text, title); });
            actions.appendChild(button);
        });
    }

    new MutationObserver(function (mutations) {
        mutations.forEach(function (mutation) {
            mutation.addedNodes.forEach(function (node) {
                if (node.nodeType === 1 && node.classList.contains('teacher-ai-message') && node.classList.contains('ai')) enhance(node);
            });
        });
    }).observe(body, { childList: true });
})();
