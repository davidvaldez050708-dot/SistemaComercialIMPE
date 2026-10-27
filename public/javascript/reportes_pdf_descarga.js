/* Descarga sin desmontar la pantalla ni abrir otro contexto del CRM. */
(function () {
    'use strict';
    if (window.IMPE_REPORT_PDF_DOWNLOAD) return;
    window.IMPE_REPORT_PDF_DOWNLOAD = true;
    const pending = new WeakSet();
    const selector = '[data-sales-report-pdf],[data-performance-pdf]';
    const filename = function (header, fallback) {
        const extended = /filename\*\s*=\s*UTF-8''([^;]+)/i.exec(header);
        const plain = /filename\s*=\s*(?:"([^"]*)"|([^;]+))/i.exec(header);
        let name = plain ? (plain[1] || plain[2] || '').trim() : fallback;
        if (extended) {
            try { name = decodeURIComponent(extended[1].trim()); } catch (_) {}
        }
        return (name || fallback).replace(/[\\/\x00-\x1f\x7f]/g, '_');
    };
    const notice = function (link, message) {
        const root = link.closest('.performance-module') || document.body;
        let box = root.querySelector('[data-pdf-download-error]');
        if (!box && message) {
            box = document.createElement('div');
            box.className = 'alert alert-danger';
            box.dataset.pdfDownloadError = '';
            box.setAttribute('role', 'alert');
            root.prepend(box);
        }
        if (box) { box.textContent = message; box.hidden = !message; }
    };
    document.addEventListener('click', async function (event) {
        const link = event.target.closest?.(selector);
        if (!link || event.defaultPrevented || event.button !== 0 ||
            event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
        event.preventDefault();
        if (pending.has(link)) return;
        pending.add(link);
        link.setAttribute('aria-busy', 'true');
        notice(link, '');
        try {
            const url = new URL(link.href, window.location.href);
            if (url.origin !== window.location.origin) throw new Error('Origen no permitido');
            const response = await fetch(url.href, { credentials: 'same-origin', cache: 'no-store' });
            if (!response.ok || response.redirected ||
                !/^application\/pdf(?:\s*;|$)/i.test(response.headers.get('Content-Type') || '')) {
                throw new Error('Respuesta sin PDF');
            }
            const data = await response.blob();
            if (!data.size || await data.slice(0,5).text() !== '%PDF-') throw new Error('PDF inválido');
            const objectUrl = URL.createObjectURL(data);
            const download = document.createElement('a');
            download.href = objectUrl;
            download.download = filename(response.headers.get('Content-Disposition') || '',
                link.dataset.pdfFilename || 'Reporte.pdf');
            download.hidden = true;
            document.body.append(download);
            download.click();
            download.remove();
            window.setTimeout(function () { URL.revokeObjectURL(objectUrl); }, 60000);
        } catch (_) {
            notice(link, 'No se pudo descargar el PDF. Recarga la pantalla y vuelve a intentarlo.');
        } finally {
            pending.delete(link);
            link.removeAttribute('aria-busy');
        }
    });
})();
