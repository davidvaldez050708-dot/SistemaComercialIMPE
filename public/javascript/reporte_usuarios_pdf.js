/* Descarga administrativa acotada; conserva filtros y evita navegar al PDF. */
(() => {
    'use strict';
    const notify = (message, error) => {
        let container = document.querySelector('.toast-container');
        if (!container) {
            container = document.createElement('div');
            container.className = 'toast-container position-fixed top-0 end-0 p-3';
            container.style.zIndex = '1095'; document.body.appendChild(container);
        }
        const el = document.createElement('div');
        el.className = 'toast system-toast' + (error ? ' system-toast-error' : '');
        el.setAttribute('role', error ? 'alert' : 'status');
        const body = document.createElement('div'); body.className = 'toast-body';
        const icon = document.createElement('i'); icon.className = 'bi ' + (error ? 'bi-exclamation-circle' : 'bi-check2-circle');
        const text = document.createElement('span'); text.textContent = message;
        body.append(icon, text); el.appendChild(body); container.appendChild(el);
        if (window.bootstrap?.Toast) {
            el.addEventListener('hidden.bs.toast', () => el.remove());
            bootstrap.Toast.getOrCreateInstance(el, {delay: error ? 6500 : 4000}).show();
        } else { el.classList.add('show'); setTimeout(() => el.remove(), 6500); }
    };
    document.querySelectorAll('[data-admin-users-pdf]').forEach(button => {
        if (button.dataset.initialized) return;
        button.dataset.initialized = '1';
        let busy = false;
        button.addEventListener('click', async event => {
            if (event.ctrlKey || event.metaKey || event.shiftKey || event.altKey || event.button) return;
            event.preventDefault(); event.stopPropagation();
            if (busy) return;
            const url = new URL(button.href, location.href);
            if (url.origin !== location.origin) return;
            busy = true; const original = button.innerHTML;
            button.setAttribute('aria-busy', 'true'); button.setAttribute('aria-disabled', 'true');
            button.textContent = 'Preparando PDF…';
            const controller = new AbortController();
            const timer = setTimeout(() => controller.abort(), 60000);
            try {
                const response = await fetch(url.href, {credentials:'same-origin', cache:'no-store',
                    headers:{'X-Requested-With':'fetch'}, signal:controller.signal});
                if (!response.ok || response.redirected || !/^application\/pdf(?:;|$)/i.test(response.headers.get('Content-Type') || '')) {
                    throw Error(response.status === 403 ? 'No tienes permiso para exportar este reporte.' :
                        response.redirected ? 'Tu sesión caducó. Inicia sesión nuevamente.' :
                        'No fue posible generar el PDF de usuarios. Intenta nuevamente.');
                }
                const blob = await response.blob();
                if ((await blob.slice(0,5).text()) !== '%PDF-') throw Error('El servidor no devolvió un PDF válido.');
                const disposition = response.headers.get('Content-Disposition') || '';
                const match = disposition.match(/filename="([^"]+)"/i);
                const filename = match && /^[A-Za-z0-9_.-]+\.pdf$/i.test(match[1]) ? match[1] : 'Reporte_Usuarios.pdf';
                const objectUrl = URL.createObjectURL(blob), link = document.createElement('a');
                link.href = objectUrl; link.download = filename; document.body.appendChild(link); link.click(); link.remove();
                setTimeout(() => URL.revokeObjectURL(objectUrl), 10000);
                notify('PDF de usuarios generado. La descarga está lista.', false);
            } catch (error) {
                notify(error.name === 'AbortError' ? 'La generación tardó demasiado. Intenta nuevamente; el reporte no se descargó.' :
                    error instanceof TypeError ? 'No se pudo conectar para descargar el PDF. Intenta nuevamente.' : error.message, true);
            } finally {
                clearTimeout(timer); busy = false; button.innerHTML = original;
                button.removeAttribute('aria-busy'); button.removeAttribute('aria-disabled');
            }
        });
    });
})();
