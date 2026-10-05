(() => {
    'use strict';
    const body = document.body;
    const toast = document.getElementById('phase3Toast');
    let toastTimer;
    const notify = (message) => {
        if (!toast) return;
        toast.textContent = message;
        toast.classList.add('show');
        clearTimeout(toastTimer);
        toastTimer = setTimeout(() => toast.classList.remove('show'), 2200);
    };
    const copyText = async (text) => {
        try { await navigator.clipboard.writeText(text); notify('Copied to clipboard.'); }
        catch (_) {
            const input = document.createElement('textarea'); input.value = text; document.body.appendChild(input); input.select();
            document.execCommand('copy'); input.remove(); notify('Copied to clipboard.');
        }
    };
    document.getElementById('copyJobNumber')?.addEventListener('click', (e) => copyText(e.currentTarget.dataset.jobNumber || ''));
    document.querySelectorAll('[data-copy-value]').forEach(el => el.addEventListener('click', () => copyText(el.dataset.copyValue || '')));

    const themeToggle = document.getElementById('themeToggle');
    const compactToggle = document.getElementById('compactToggle');
    const savedTheme = localStorage.getItem('hpuRequestTheme');
    const savedCompact = localStorage.getItem('hpuRequestCompact') === '1';
    if (savedTheme === 'dark') body.classList.add('phase3-dark');
    if (savedCompact) body.classList.add('phase3-compact');
    const syncButtons = () => {
        const dark = body.classList.contains('phase3-dark');
        const compact = body.classList.contains('phase3-compact');
        if (themeToggle) { themeToggle.innerHTML = `<i class="bi ${dark ? 'bi-sun' : 'bi-moon-stars'}"></i> ${dark ? 'Light' : 'Dark'}`; themeToggle.setAttribute('aria-pressed', String(dark)); }
        if (compactToggle) { compactToggle.innerHTML = `<i class="bi ${compact ? 'bi-arrows-expand' : 'bi-arrows-collapse'}"></i> ${compact ? 'Comfortable' : 'Compact'}`; compactToggle.setAttribute('aria-pressed', String(compact)); }
    };
    syncButtons();
    themeToggle?.addEventListener('click', () => { body.classList.toggle('phase3-dark'); localStorage.setItem('hpuRequestTheme', body.classList.contains('phase3-dark') ? 'dark' : 'light'); syncButtons(); });
    compactToggle?.addEventListener('click', () => { body.classList.toggle('phase3-compact'); localStorage.setItem('hpuRequestCompact', body.classList.contains('phase3-compact') ? '1' : '0'); syncButtons(); });

    const sections = [...document.querySelectorAll('.request-view-page > section.card')];
    const menu = document.getElementById('sectionMenu');
    const menuBtn = document.getElementById('sectionMenuButton');
    sections.forEach((section, index) => {
        section.classList.add('phase3-section-anchor', 'phase3-reveal');
        if (!section.id) section.id = `request-section-${index + 1}`;
        const title = section.querySelector('.section-banner')?.textContent.trim() || `Section ${index + 1}`;
        if (menu) {
            const a = document.createElement('a'); a.href = `#${section.id}`; a.innerHTML = `<i class="bi bi-chevron-right"></i><span>${title}</span>`;
            a.addEventListener('click', () => { menu.hidden = true; menuBtn?.setAttribute('aria-expanded', 'false'); }); menu.appendChild(a);
        }
    });
    menuBtn?.addEventListener('click', () => { if (!menu) return; menu.hidden = !menu.hidden; menuBtn.setAttribute('aria-expanded', String(!menu.hidden)); });
    document.addEventListener('click', (e) => { if (menu && !menu.hidden && !menu.contains(e.target) && !menuBtn?.contains(e.target)) { menu.hidden = true; menuBtn?.setAttribute('aria-expanded', 'false'); } });

    const observer = 'IntersectionObserver' in window ? new IntersectionObserver(entries => entries.forEach(entry => { if (entry.isIntersecting) { entry.target.classList.add('phase3-visible'); observer.unobserve(entry.target); } }), { threshold: .08 }) : null;
    sections.forEach(section => observer ? observer.observe(section) : section.classList.add('phase3-visible'));

    const progress = document.getElementById('pageScrollProgress');
    const updateProgress = () => { const max = document.documentElement.scrollHeight - innerHeight; const pct = max > 0 ? Math.min(100, Math.max(0, scrollY / max * 100)) : 0; if (progress) progress.style.width = `${pct}%`; };
    addEventListener('scroll', updateProgress, { passive: true }); updateProgress();

    // Upgrade attachment buttons & links into a rich file preview experience.
    function getPreviewModal() {
        return document.getElementById('phase3PreviewModal') || document.getElementById('filePreviewModal');
    }

    function showFilePreview(url, name, ext) {
        const modalEl = getPreviewModal();
        if (!modalEl) return;
        const modalBody = modalEl.querySelector('.modal-body') || document.getElementById('phase3PreviewBody');
        const modalTitle = modalEl.querySelector('.modal-title');
        if (!modalBody) return;

        const bsModal = (window.bootstrap && window.bootstrap.Modal) ? (bootstrap.Modal.getInstance(modalEl) || new bootstrap.Modal(modalEl)) : null;

        const fileExt = (ext || url.split('.').pop().split('?')[0] || '').toLowerCase();
        const fileName = name || url.split('/').pop().split('?')[0] || 'Attachment Preview';

        if (modalTitle) {
            modalTitle.innerHTML = `<i class="bi bi-file-earmark-text me-2 text-primary"></i><span>${fileName}</span>`;
        }

        modalBody.innerHTML = '';

        const isAbsoluteHttp = /^https?:\/\//i.test(url);
        let fullUrl = isAbsoluteHttp ? url : (window.location.origin + (url.startsWith('/') ? url : '/' + url));

        let inlineUrl = fullUrl;
        if ((inlineUrl.includes('download.php') || inlineUrl.includes('publication_requests.php')) && !inlineUrl.includes('inline=1')) {
            inlineUrl += (inlineUrl.includes('?') ? '&' : '?') + 'inline=1';
        }

        if (['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg'].includes(fileExt)) {
            modalBody.innerHTML = `<div class="text-center p-2"><img class="phase3-preview-image img-fluid rounded shadow-sm" src="${inlineUrl}" alt="${fileName}"></div>`;
        } else if (fileExt === 'pdf') {
            modalBody.innerHTML = `<iframe class="phase3-preview-frame w-100" style="height: 75vh; border: 0;" src="${inlineUrl}" title="${fileName}"></iframe>`;
        } else if (['doc', 'docx'].includes(fileExt)) {
            modalBody.innerHTML = `
                <div class="d-flex flex-column h-100" style="min-height: 480px;">
                    <div class="d-flex align-items-center justify-content-between bg-light p-2 border-bottom rounded-top text-xs mb-2">
                        <span class="fw-bold text-dark"><i class="bi bi-file-earmark-word me-1 text-primary"></i> ${fileName}</span>
                        <div class="d-flex gap-2">
                            <a href="${fullUrl}" target="_blank" download class="btn btn-sm btn-primary"><i class="bi bi-download me-1"></i> Download Word File</a>
                            <a href="${inlineUrl}" target="_blank" class="btn btn-sm btn-outline-secondary"><i class="bi bi-box-arrow-up-right me-1"></i> Direct Link</a>
                        </div>
                    </div>
                    <div id="docxPreviewContainer" class="flex-grow-1 p-3 bg-white border rounded shadow-sm overflow-auto" style="max-height: 70vh; font-family: 'Segoe UI', Arial, sans-serif; line-height: 1.65; color: #0f172a;">
                        <div class="text-center py-5" id="docxLoading">
                            <div class="spinner-border text-primary mb-3" role="status"></div>
                            <div class="fw-bold text-dark">Rendering Word Document...</div>
                            <div class="text-muted small">${fileName}</div>
                        </div>
                    </div>
                </div>
            `;

            fetch(inlineUrl, { credentials: 'same-origin' })
                .then(response => {
                    if (!response.ok) throw new Error("HTTP error " + response.status);
                    return response.arrayBuffer();
                })
                .then(arrayBuffer => {
                    if (window.mammoth && typeof mammoth.convertToHtml === "function") {
                        return mammoth.convertToHtml({ arrayBuffer: arrayBuffer });
                    } else {
                        throw new Error("Mammoth library not ready");
                    }
                })
                .then(result => {
                    const container = document.getElementById('docxPreviewContainer');
                    if (container) {
                        container.innerHTML = result.value
                            ? `<div class="docx-rendered-content p-3">${result.value}</div>`
                            : `<div class="text-muted text-center py-4">Document content is empty.</div>`;
                    }
                })
                .catch(err => {
                    const container = document.getElementById('docxPreviewContainer');
                    if (container) {
                        container.innerHTML = `
                            <div class="text-center py-5">
                                <i class="bi bi-file-earmark-word fs-1 text-primary d-block mb-3"></i>
                                <h6 class="fw-bold text-dark mb-2">${fileName}</h6>
                                <p class="text-muted small mb-3">In-browser text preview could not be rendered for this file format.</p>
                                <div class="d-flex justify-content-center gap-2">
                                    <a href="${fullUrl}" target="_blank" download class="btn btn-sm btn-primary"><i class="bi bi-download me-1"></i> Download Word File</a>
                                    <a href="${inlineUrl}" target="_blank" class="btn btn-sm btn-outline-secondary"><i class="bi bi-box-arrow-up-right me-1"></i> Open Directly</a>
                                </div>
                            </div>
                        `;
                    }
                });
        } else if (['xls', 'xlsx', 'ppt', 'pptx'].includes(fileExt)) {
            modalBody.innerHTML = `
                <div class="d-flex flex-column h-100" style="min-height: 480px;">
                    <div class="d-flex align-items-center justify-content-between bg-light p-2 border-bottom rounded-top text-xs mb-2">
                        <span class="fw-bold text-dark"><i class="bi bi-file-earmark-excel me-1 text-success"></i> ${fileName} (${fileExt.toUpperCase()})</span>
                        <div class="d-flex gap-2">
                            <a href="${fullUrl}" target="_blank" download class="btn btn-sm btn-primary"><i class="bi bi-download me-1"></i> Download File</a>
                            <a href="${fullUrl}" target="_blank" class="btn btn-sm btn-outline-secondary"><i class="bi bi-box-arrow-up-right me-1"></i> Direct Link</a>
                        </div>
                    </div>
                    <div class="text-center py-5 bg-light rounded border">
                        <i class="bi bi-file-earmark-spreadsheet fs-1 text-success d-block mb-3"></i>
                        <h6 class="fw-bold text-dark mb-2">${fileName}</h6>
                        <p class="text-muted small mb-4">Click download to open and view spreadsheet data locally.</p>
                        <a href="${fullUrl}" target="_blank" download class="btn btn-primary"><i class="bi bi-download me-1"></i> Download ${fileExt.toUpperCase()} File</a>
                    </div>
                </div>
            `;
        } else if (['mp4', 'mov', 'webm', 'mkv'].includes(fileExt)) {
            modalBody.innerHTML = `<div class="text-center p-2"><video src="${inlineUrl}" controls autoplay class="w-100 rounded" style="max-height: 70vh;"></video></div>`;
        } else if (['mp3', 'wav', 'ogg', 'm4a'].includes(fileExt)) {
            modalBody.innerHTML = `<div class="p-4 text-center"><audio src="${inlineUrl}" controls autoplay class="w-100"></audio></div>`;
        } else if (['txt', 'log', 'csv', 'json', 'xml', 'html', 'php', 'js', 'css', 'sql'].includes(fileExt)) {
            modalBody.innerHTML = `<iframe class="phase3-preview-frame w-100 bg-white p-3" style="height: 65vh; border: 0;" src="${inlineUrl}"></iframe>`;
        } else {
            modalBody.innerHTML = `
                <div class="text-center py-5">
                    <i class="bi bi-file-earmark-arrow-down fs-1 text-muted d-block mb-3"></i>
                    <h6 class="fw-bold text-dark mb-2">${fileName}</h6>
                    <p class="text-muted small mb-4">Direct in-browser preview is not supported for <code>.${fileExt}</code> files.</p>
                    <a href="${fullUrl}" target="_blank" download class="btn btn-primary"><i class="bi bi-download me-1"></i> Download File</a>
                </div>
            `;
        }

        if (bsModal) bsModal.show();
    }

    document.addEventListener('click', (event) => {
        const btn = event.target && event.target.closest ? event.target.closest('[data-file-url], .file-preview-btn, .file-view-btn') : null;
        if (btn) {
            const url = btn.getAttribute('data-file-url') || btn.getAttribute('href');
            if (url && url !== '#') {
                event.preventDefault();
                const name = btn.getAttribute('data-file-name') || btn.getAttribute('title') || '';
                const ext = btn.getAttribute('data-file-ext') || '';
                showFilePreview(url, name, ext);
            }
            return;
        }

        const link = event.target && event.target.closest ? event.target.closest('a[href]') : null;
        if (link && !link.classList.contains('no-preview') && !link.hasAttribute('download')) {
            const href = link.getAttribute('href') || '';
            if (/\.(pdf|doc|docx|xls|xlsx|ppt|pptx|png|jpe?g|gif|webp|mp4|mov|mp3|wav)(\?.*)?$/i.test(href)) {
                if (event.ctrlKey || event.metaKey) return;
                event.preventDefault();
                showFilePreview(href, link.textContent.trim(), '');
            }
        }
    });
})();
