import '../css/document-preview.css';

// A receipt is usable only after the complete response and first page are rendered.
const DOWNLOAD_TIMEOUT_MS = 150_000;
const BODY_IDLE_TIMEOUT_MS = 30_000;
const RENDER_TIMEOUT_MS = 45_000;
const MAX_PROCESSING_RETRIES = 3;

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
        const pages = preview.querySelector('[data-preview-pages]');
        const reviewable = preview.dataset.reviewable === '1';
        let sequence = 0;
        let controller;
        let objectUrl;
        let disposeViewer;
        let replacementSelected = false;
        const timers = new Set();
        const schedule = (callback, ms) => {
            const timer = setTimeout(() => { timers.delete(timer); callback(); }, ms);
            timers.add(timer);
            return timer;
        };
        const cancelTimer = (timer) => { clearTimeout(timer); timers.delete(timer); };
        const clearTimers = () => { timers.forEach(clearTimeout); timers.clear(); };
        function setReview(token = '') {
            if (!reviewable) return;
            root.querySelectorAll('[data-document-review-token]').forEach(input => { input.value = token; });
            root.querySelectorAll('[data-document-submit]').forEach(button => { button.disabled = !token; });
        }
        function stop() {
            clearTimers();
            controller?.abort();
            disposeViewer?.();
            disposeViewer = undefined;
            if (pages) pages.hidden = true;
            frame.onload = null;
            frame.hidden = true;
            frame.removeAttribute('src');
            if (objectUrl) URL.revokeObjectURL(objectUrl);
            objectUrl = undefined;
            open.hidden = true;
            setReview();
        }
        function replacementMessage() {
            status.textContent = 'Berkas pengganti dipilih. Klik Unggah dokumen untuk memproses dan memeriksa hasilnya.';
            retry.hidden = true;
        }
        async function load(processingAttempt = 0) {
            if (replacementSelected) return;
            const current = ++sequence;
            stop();
            const requestController = new AbortController();
            controller = requestController;
            const signal = requestController.signal;
            retry.textContent = 'Muat ulang';
            retry.hidden = false;
            warnings.hidden = true;
            status.textContent = 'Menyiapkan PDF…';
            const pageCount = preview.querySelector('[data-preview-page-count]');
            if (pageCount) pageCount.textContent = 'PDF';
            let requestId = '';
            function fail(message) {
                if (current !== sequence) return;
                clearTimers();
                setReview();
                status.textContent = message;
                retry.textContent = 'Coba lagi';
                retry.hidden = false;
            }
            function timeout(message) {
                if (current !== sequence) return;
                fail(message);
                ++sequence; // Late responses/render callbacks cannot restore this receipt.
                requestController.abort();
                disposeViewer?.();
            }
            let deadline = schedule(() => timeout('Pratinjau terlalu lama dimuat. Periksa koneksi lalu klik Coba lagi.'), DOWNLOAD_TIMEOUT_MS);
            const url = new URL(preview.dataset.url, window.location.origin);
            if (markers) url.searchParams.set('markers', markers.checked ? '1' : '0');
            try {
                const response = await fetch(url, {
                    headers: { Accept: 'application/json' },
                    credentials: 'same-origin', cache: 'no-store', signal,
                });
                if (current !== sequence) return;
                requestId = response.headers.get('X-Preview-Request-Id') || '';
                if (!response.ok) {
                    if (response.status === 503 && response.headers.get('X-Preview-State') === 'processing' && processingAttempt < MAX_PROCESSING_RETRIES) {
                        clearTimers();
                        await response.body?.cancel();
                        if (current !== sequence) return;
                        status.textContent = 'PDF sedang diproses. Mencoba lagi sebentar…';
                        schedule(() => { if (current === sequence) load(processingAttempt + 1); }, 2000);
                        return;
                    }
                    const error = await response.json().catch(() => ({}));
                    if (response.status === 401) throw new Error('Sesi telah berakhir. Muat ulang halaman dan masuk kembali.');
                    throw new Error(error.message || 'Pratinjau belum dapat dimuat. Coba lagi atau hubungi administrator.');
                }
                if (!response.headers.get('content-type')?.includes('application/pdf')) {
                    throw new Error('Sesi mungkin telah berakhir. Muat ulang halaman untuk melanjutkan.');
                }
                const expected = Number(response.headers.get('X-Preview-Bytes')) || 0;
                const chunks = [];
                let received = 0;
                let idle;
                const reader = response.body?.getReader();
                let blob;
                if (reader) {
                    try {
                        while (true) {
                            idle = schedule(() => timeout('Unduhan PDF terhenti. Periksa koneksi lalu klik Coba lagi.'), BODY_IDLE_TIMEOUT_MS);
                            const { done, value } = await reader.read();
                            cancelTimer(idle);
                            if (current !== sequence) return;
                            if (done) break;
                            chunks.push(value);
                            received += value.byteLength;
                            status.textContent = expected
                                ? `Mengunduh PDF… ${Math.min(100, Math.floor(received / expected * 100))}%`
                                : `Mengunduh PDF… ${Math.ceil(received / 1024)} KB`;
                        }
                    } finally {
                        cancelTimer(idle);
                        reader.releaseLock();
                    }
                    blob = new Blob(chunks, { type: 'application/pdf' });
                } else {
                    blob = await response.blob();
                }
                if (current !== sequence) return;
                if (!blob.size || (expected && blob.size !== expected)) {
                    throw new Error('Unduhan PDF tidak lengkap. Periksa koneksi lalu klik Coba lagi.');
                }
                cancelTimer(deadline);
                deadline = schedule(() => timeout('Halaman PDF terlalu lama ditampilkan. Klik Coba lagi.'), RENDER_TIMEOUT_MS);
                status.textContent = 'Menampilkan halaman PDF…';
                const token = response.headers.get('X-Document-Review') || '';
                warnings.textContent = decodeURIComponent(response.headers.get('X-Preview-Warnings') || '');
                warnings.hidden = !warnings.textContent;
                objectUrl = URL.createObjectURL(blob);
                open.href = objectUrl;
                open.hidden = false;
                const ready = () => {
                    if (current !== sequence) return;
                    clearTimers();
                    setReview(token);
                    const missingReceipt = reviewable && !token;
                    status.textContent = missingReceipt
                        ? 'PDF sudah tampil, tetapi pengajuan belum diizinkan. Periksa peringatan di atas; jika tidak ada, klik Coba lagi atau muat ulang halaman.'
                        : '';
                    retry.textContent = 'Coba lagi';
                    retry.hidden = !missingReceipt;
                };
                if (reviewable) {
                    const { renderPdfPreview } = await import('./pdf-preview-viewer');
                    if (current !== sequence) return;
                    pages.hidden = false;
                    const dispose = await renderPdfPreview(pages, blob, {
                        signal, showMarkers: markers?.checked ?? true,
                        onReady: ready,
                        onError: () => {
                            if (current !== sequence) return;
                            console.warn('PDF preview rendering failed', { requestId });
                            fail('Halaman PDF belum dapat ditampilkan. Klik Coba lagi.');
                        },
                    });
                    if (current !== sequence) dispose();
                    else disposeViewer = dispose;
                } else {
                    frame.onload = ready;
                    frame.src = objectUrl + (preview.dataset.hideToolbar === '1' ? '#toolbar=0&navpanes=0' : '');
                    frame.hidden = false;
                }
            } catch (error) {
                if (current !== sequence || signal.aborted) return;
                console.warn('PDF preview failed', { requestId, errorType: error.name });
                fail(error.name === 'TypeError'
                    ? 'Unduhan PDF gagal atau koneksi terputus. Klik Coba lagi.'
                    : error.message);
            }
        }
        markers?.addEventListener('change', () => load());
        retry.addEventListener('click', () => load());
        const replacementInputs = () => [...root.querySelectorAll('[data-document-reupload] input[type="file"]')];
        if (reviewable) {
            root.querySelectorAll('[data-document-reupload]').forEach(form => {
                form.querySelector('input[type="file"]')?.addEventListener('change', () => {
                    replacementSelected = replacementInputs().some(input => input.files.length > 0);
                    ++sequence;
                    stop();
                    if (replacementSelected) replacementMessage();
                    else load();
                });
                form.addEventListener('submit', () => {
                    ++sequence;
                    stop();
                    status.textContent = 'Mengunggah dokumen…';
                    retry.hidden = true;
                    form.querySelector('button[type="submit"]').disabled = true;
                });
            });
        }
        window.addEventListener('pagehide', () => { ++sequence; stop(); });
        window.addEventListener('pageshow', (event) => {
            if (!event.persisted) return;
            root.querySelectorAll('[data-document-reupload] button[type="submit"]').forEach(button => { button.disabled = false; });
            replacementSelected = replacementInputs().some(input => input.files.length > 0);
            if (replacementSelected) replacementMessage();
            else load();
        });
        load();
    });
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => initDocumentPreviews());
} else {
    initDocumentPreviews();
}
