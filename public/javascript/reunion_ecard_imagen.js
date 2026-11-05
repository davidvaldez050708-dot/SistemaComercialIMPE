(function () {
    'use strict';
    document.addEventListener('DOMContentLoaded', function () {
        const modal = document.getElementById('modalAgendaCorreo');
        const form = modal?.querySelector('[data-agenda-mail-form]');
        const file = form?.querySelector('[data-ecard-upload-file]');
        const section = form?.querySelector('[data-agenda-mail-ecard]');
        if (!file || !section) return;
        const expected = form.querySelector('[data-ecard-upload-expected]');
        const reset = form.querySelector('[data-ecard-upload-reset]');
        const status = form.querySelector('[data-ecard-upload-status]');
        let url = '', generation = 0, pending = false;
        const restorePreview = function () {
            const select = section.querySelector('[data-ecard-template]');
            if (select) { select.closest('.agenda-ecard-selector').hidden = false; select.disabled = false; select.dispatchEvent(new Event('change', { bubbles: true })); }
            const copy = section.querySelector('.agenda-mail-ecard-copy small');
            if (copy) copy.textContent = 'Se generará con los datos actuales de la reunión.';
        };
        const clear = function (restore) {
            generation++; pending = false;
            if (url) URL.revokeObjectURL(url);
            url = ''; file.value = ''; file.setCustomValidity('');
            expected.value = '0'; reset.hidden = true;
            status.textContent = ''; status.classList.remove('is-error');
            if (restore) restorePreview();
        };
        reset.addEventListener('click', function () { clear(true); });
        // Cada apertura para otra reunión reconstruye este bloque. No limpiar al
        // ampliar la vista previa: ese paso oculta temporalmente el modal de correo.
        new MutationObserver(function () { clear(false); }).observe(section, { childList: true });
        file.addEventListener('change', function () {
            generation++; const token = generation;
            if (url) URL.revokeObjectURL(url);
            url = ''; pending = false; restorePreview();
            const selected = file.files[0]; expected.value = selected ? '1' : '0';
            reset.hidden = !selected; status.classList.remove('is-error'); file.setCustomValidity('');
            if (!selected) { status.textContent = ''; return; }
            const fail = function (message) {
                if (token !== generation) return;
                pending = false; file.setCustomValidity(message); status.textContent = message;
                status.classList.add('is-error');
                if (url) URL.revokeObjectURL(url); url = '';
            };
            if (!/\.(jpe?g|png)$/i.test(selected.name) || (selected.type && !['image/jpeg', 'image/png'].includes(selected.type)) || selected.size === 0 || selected.size > 5 * 1024 * 1024) {
                fail('Selecciona una imagen JPG o PNG de hasta 5 MB.'); return;
            }
            pending = true; status.textContent = 'Preparando vista previa…';
            url = URL.createObjectURL(selected);
            const image = new Image();
            image.onload = function () {
                if (token !== generation) return;
                if (image.width > 6000 || image.height > 6000 || image.width * image.height > 12000000) {
                    fail('Utiliza una imagen de hasta 12 megapíxeles y 6,000 píxeles por lado.'); return;
                }
                pending = false;
                const preview = section.querySelector('[data-ecard-preview-image]');
                if (preview) preview.src = url;
                const speaker = section.querySelector('[data-ecard-speaker]');
                if (speaker) speaker.textContent = 'Ecard personalizada';
                const copy = section.querySelector('.agenda-mail-ecard-copy small');
                if (copy) copy.textContent = 'Esta imagen reemplazará la Ecard predeterminada en este correo.';
                const select = section.querySelector('[data-ecard-template]');
                if (select) { select.disabled = true; select.closest('.agenda-ecard-selector').hidden = true; }
                status.textContent = 'Imagen lista. Revisa la vista previa antes de enviar.';
            };
            image.onerror = function () { fail('No se pudo leer la imagen. Selecciona otra imagen o usa la predeterminada.'); };
            image.src = url;
        });
        window.addEventListener('submit', function (event) {
            if (event.target !== form) return;
            if (pending || !file.checkValidity()) {
                event.preventDefault(); event.stopImmediatePropagation();
                if (pending) status.textContent = 'Espera a que termine de prepararse la vista previa.';
                else file.reportValidity();
            }
        }, true);
        window.addEventListener('pagehide', function () { if (url) URL.revokeObjectURL(url); });
    });
})();
