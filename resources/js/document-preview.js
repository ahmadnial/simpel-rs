import '../css/document-preview.css';

// PDF bytes and the review receipt originate from the same authenticated response.
export function initDocumentPreviews(root = document) {
    root.querySelectorAll('[data-document-preview]').forEach((preview) => {
        if (preview.dataset.initialized) return;
        preview.dataset.initialized = '1';
        const frame = preview.querySelector('[data-preview-frame]');
        const status = preview.querySelector('[data-preview-status]');
        const warnings = preview.querySelector('[data-preview-warnings]');
        const retry = preview.querySelector('[data-preview-retry]');
        const open = preview.querySelector('[data-preview-open]');
        const markers = preview.querySelector('[data-preview-markers]');
        const reviewable = preview.dataset.reviewable === '1';
        let sequence = 0;
        let controller;
        let objectUrl;
        let disposeViewer;
        let replacementSelected = false;
        const pages = preview.querySelector('[data-preview-pages]');

        function setReview(token = '') {
            if (!reviewable) return;
            root.querySelectorAll('[data-document-review-token]').forEach(input => { input.value = token; });
            root.querySelectorAll('[data-document-submit]').forEach(button => { button.disabled = !token; });
        }

        async function load() {
            if (replacementSelected) return;
            disposeViewer?.();
            disposeViewer = undefined;
            if (pages) pages.hidden = true;
            const current = ++sequence;
            controller?.abort();
            controller = new AbortController();
            setReview();
            frame.onload = null;
            frame.hidden = true;
            frame.removeAttribute('src');
            if (objectUrl) URL.revokeObjectURL(objectUrl);
            objectUrl = undefined;
            open.hidden = true;
            retry.hidden = true;
            warnings.hidden = true;
            status.textContent = 'Memuat pratinjau…';
            const pageCount = preview.querySelector('[data-preview-page-count]');
            if (pageCount) pageCount.textContent = 'PDF';
            const url = new URL(preview.dataset.url, window.location.origin);
            if (markers) url.searchParams.set('markers', markers.checked ? '1' : '0');
            try {
                const response = await fetch(url, {
                    headers: { Accept: 'application/json' },
                    credentials: 'same-origin', cache: 'no-store', signal: controller.signal,
                });
                if (!response.ok) {
                    const error = await response.json().catch(() => ({}));
                    throw new Error(error.message || 'Pratinjau belum dapat dimuat. Coba lagi atau hubungi administrator.');
                }
                if (!response.headers.get('content-type')?.includes('application/pdf')) {
                    throw new Error('Sesi mungkin telah berakhir. Muat ulang halaman untuk melanjutkan.');
                }
                const blob = await response.blob();
                if (current !== sequence) return;
                const token = response.headers.get('X-Document-Review') || '';
                warnings.textContent = decodeURIComponent(response.headers.get('X-Preview-Warnings') || '');
                warnings.hidden = !warnings.textContent;
                objectUrl = URL.createObjectURL(blob);
                open.href = objectUrl;
                open.hidden = false;
                const ready = (value) => {
                    if (current !== sequence) return;
                    setReview(value ? token : '');
                    status.textContent = value ? '' : 'Memuat halaman PDF…';
                    retry.hidden = true;
                };
                if (reviewable) {
                    const { renderPdfPreview } = await import('./pdf-preview-viewer');
                    if (current !== sequence) return;
                    pages.hidden = false;
                    const dispose = await renderPdfPreview(pages, blob, {
                        signal: controller.signal,
                        showMarkers: markers?.checked ?? true,
                        onReady: ready,
                        onError: (error) => {
                            console.error('PDF preview rendering failed:', error);
                            if (current !== sequence) return;
                            setReview();
                            status.textContent = 'Halaman PDF belum dapat ditampilkan. Muat ulang pratinjau.';
                            retry.hidden = false;
                        },
                    });
                    if (current !== sequence) dispose();
                    else disposeViewer = dispose;
                } else {
                    frame.onload = () => ready(true);
                    frame.src = objectUrl + (preview.dataset.hideToolbar === '1' ? '#toolbar=0&navpanes=0' : '');
                    frame.hidden = false;
                }
            } catch (error) {
                if (current !== sequence || error.name === 'AbortError') return;
                status.textContent = error.message;
                retry.hidden = false;
                setReview();
            }
        }
        markers?.addEventListener('change', load);
        retry.addEventListener('click', load);
        if (reviewable) {
            root.querySelectorAll('[data-document-reupload]').forEach(form => {
                form.querySelector('input[type="file"]')?.addEventListener('change', () => {
                    replacementSelected = [...root.querySelectorAll('[data-document-reupload] input[type="file"]')].some(input => input.files.length > 0);
                    ++sequence;
                    controller?.abort();
                    disposeViewer?.();
                    if (pages) pages.hidden = true;
                    setReview();
                    frame.onload = null;
                    frame.hidden = true;
                    open.hidden = true;
                    status.textContent = 'Berkas pengganti dipilih. Klik Unggah dokumen untuk memproses dan memeriksa hasilnya.';
                    retry.hidden = true;
                    if (!replacementSelected) load();
                });
                form.addEventListener('submit', () => {
                    setReview();
                    status.textContent = 'Mengunggah dokumen…';
                    form.querySelector('button[type="submit"]').disabled = true;
                });
            });
        }
        window.addEventListener('pagehide', () => {
            controller?.abort();
            disposeViewer?.();
            if (objectUrl) URL.revokeObjectURL(objectUrl);
        }, { once: true });
        load();
    });
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => initDocumentPreviews());
} else {
    initDocumentPreviews();
}
