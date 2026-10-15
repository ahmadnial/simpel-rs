import { getDocument, GlobalWorkerOptions } from 'pdfjs-dist';
import workerUrl from 'pdfjs-dist/build/pdf.worker.min.mjs?url';

GlobalWorkerOptions.workerSrc = workerUrl;

// Keep page placeholders for natural scrolling, and rasterize only nearby pages.
export async function renderPdfPreview(container, blob, { onReady, onError, signal, showMarkers }) {
    const task = getDocument({ data: new Uint8Array(await blob.arrayBuffer()), isEvalSupported: false });
    const wanted = new Set();
    const rendered = new Set();
    const papers = [];
    let disposed = false;
    let rendering;
    let observer;
    let viewer;
    let pumping = false;
    let failed = false;
    let scrollFrame;
    const label = container.closest('[data-document-preview]')?.querySelector('[data-preview-page-count]');
    const release = (paper) => {
        paper.querySelectorAll('canvas').forEach(canvas => { canvas.width = 0; canvas.height = 0; });
        paper.replaceChildren();
        rendered.delete(paper);
    };
    const dispose = () => {
        if (disposed) return;
        disposed = true;
        observer?.disconnect();
        cancelAnimationFrame(scrollFrame);
        rendering?.cancel();
        papers.forEach(release);
        task.destroy();
        viewer?.remove();
    };
    signal.addEventListener('abort', dispose, { once: true });
    if (signal.aborted) { dispose(); return dispose; }

    try {
        const pdf = await task.promise;
        const firstPage = await pdf.getPage(1);
        if (disposed) return dispose;
        const initialViewport = firstPage.getViewport({ scale: 1 });
        viewer = document.createElement('div');
        viewer.className = 'document-preview-scroll';
        viewer.tabIndex = 0;
        viewer.setAttribute('role', 'region');
        viewer.setAttribute('aria-label', `Dokumen PDF, ${pdf.numPages} halaman. Gulir untuk membaca.`);
        container.replaceChildren(viewer);
        for (let number = 1; number <= pdf.numPages; number++) {
            const paper = document.createElement('div');
            paper.className = 'document-preview-page';
            paper.dataset.pageNumber = number;
            paper.style.aspectRatio = `${initialViewport.width} / ${initialViewport.height}`;
            paper.setAttribute('aria-label', `Halaman ${number}`);
            papers.push(paper);
            viewer.appendChild(paper);
        }

        function updatePageCount() {
            if (!label || disposed) return;
            const top = viewer.getBoundingClientRect().top + 24;
            const current = papers.find(paper => paper.getBoundingClientRect().bottom > top) || papers.at(-1);
            label.textContent = `Halaman ${current.dataset.pageNumber} dari ${pdf.numPages}`;
        }
        viewer.addEventListener('scroll', () => {
            cancelAnimationFrame(scrollFrame);
            scrollFrame = requestAnimationFrame(updatePageCount);
        }, { passive: true });
        updatePageCount();

        async function renderPage(paper) {
            const number = Number(paper.dataset.pageNumber);
            const page = await pdf.getPage(number);
            if (disposed) return;
            try {
                const viewport = page.getViewport({ scale: 1 });
                paper.style.aspectRatio = `${viewport.width} / ${viewport.height}`;
                const scale = Math.min(1.5, 1800 / Math.max(viewport.width, viewport.height));
                const canvas = document.createElement('canvas');
                const renderedViewport = page.getViewport({ scale });
                canvas.width = Math.ceil(renderedViewport.width);
                canvas.height = Math.ceil(renderedViewport.height);
                canvas.setAttribute('role', 'img');
                canvas.setAttribute('aria-label', `Halaman ${number} dari ${pdf.numPages}. Gunakan Buka PDF untuk membaca atau mencari teks.`);
                paper.replaceChildren(canvas);
                rendering = page.render({ canvasContext: canvas.getContext('2d'), viewport: renderedViewport });
                await rendering.promise;
                const annotations = showMarkers ? await page.getAnnotations() : [];
                if (disposed) return;
                for (const annotation of annotations) {
                    const match = /^https:\/\/simpel-preview\.invalid\/variable\/([a-z_]+)$/.exec(annotation.url || '');
                    if (!match || !annotation.rect) continue;
                    const rect = [...viewport.convertToViewportPoint(annotation.rect[0], annotation.rect[1]), ...viewport.convertToViewportPoint(annotation.rect[2], annotation.rect[3])];
                    const box = document.createElement('span');
                    box.className = 'document-preview-date';
                    box.title = `Tanggal dari variabel: \${${match[1]}}`;
                    box.setAttribute('aria-label', box.title);
                    box.dataset.dateVariable = match[1];
                    box.style.cssText = `left:${Math.min(rect[0], rect[2]) / viewport.width * 100}%;top:${Math.min(rect[1], rect[3]) / viewport.height * 100}%;width:${Math.abs(rect[2] - rect[0]) / viewport.width * 100}%;height:${Math.abs(rect[3] - rect[1]) / viewport.height * 100}%`;
                    paper.appendChild(box);
                }
                rendered.add(paper);
            } finally {
                rendering = undefined;
                if (!disposed) page.cleanup();
            }
        }

        async function pump() {
            if (pumping || disposed || failed) return;
            pumping = true;
            try {
                let paper;
                while (!disposed && (paper = [...wanted].find(candidate => !rendered.has(candidate)))) {
                    await renderPage(paper);
                    if (disposed) break;
                    if (!wanted.has(paper)) release(paper);
                }
            } catch (error) {
                if (!disposed) { failed = true; onError(error); }
            } finally {
                pumping = false;
            }
        }

        // The first rendered page enables submission; scrolling never replaces its receipt.
        await renderPage(papers[0]);
        if (disposed) return dispose;
        onReady(true);
        observer = new IntersectionObserver(entries => {
            for (const { target, isIntersecting } of entries) {
                if (isIntersecting) wanted.add(target);
                else {
                    wanted.delete(target);
                    // An in-flight render releases itself after completion.
                    if (rendered.has(target)) release(target);
                }
            }
            pump();
        }, { root: viewer, rootMargin: '600px 0px' });
        papers.forEach(paper => observer.observe(paper));
    } catch (error) {
        dispose();
        throw error;
    }
    return dispose;
}
