(function () {
    'use strict';
    const form = document.querySelector('[data-inscripcion-completa]');
    if (!form || form.dataset.inicializado) return;
    form.dataset.inicializado = '1';
    const field = name => form.elements.namedItem(name);
    const state = document.querySelector('[data-inscripcion-estado]');
    const toast = function (message, error) {
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
        const span = document.createElement('span'); span.textContent = message;
        body.append(icon, span); el.appendChild(body); container.appendChild(el);
        if (window.bootstrap && bootstrap.Toast) {
            el.addEventListener('hidden.bs.toast', () => el.remove());
            bootstrap.Toast.getOrCreateInstance(el, {delay: error ? 6000 : 4000}).show();
        } else { el.classList.add('show'); setTimeout(() => el.remove(), 6000); }
    };
    const errorFor = function (name, text) {
        const input = field(name), error = document.getElementById('error_' + name);
        if (input) { input.classList.add('is-invalid'); input.setAttribute('aria-invalid', 'true'); if (error) input.setAttribute('aria-describedby', error.id); }
        if (error) error.textContent = text;
    };
    form.addEventListener('input', e => {
        const input = e.target;
        input.classList.remove('is-invalid'); input.removeAttribute('aria-invalid');
        const error = document.getElementById('error_' + input.name); if (error) error.textContent = '';
    });
    let revision = 0;
    field('entidad_federativa').addEventListener('change', async function () {
        const seq = ++revision, municipio = field('municipio');
        municipio.replaceChildren(new Option('Cargando municipios…', '')); municipio.disabled = true;
        if (!this.value) { municipio.replaceChildren(new Option('Primero selecciona un estado', '')); return; }
        try {
            const url = new URL(form.dataset.municipiosUrl, location.href); url.searchParams.set('estado_id', this.value);
            const response = await fetch(url, {credentials:'same-origin', headers:{'X-Requested-With':'fetch'}});
            const data = await response.json(); if (seq !== revision) return;
            if (!response.ok || !data.ok) throw new Error(data.mensaje || 'No se pudieron consultar los municipios.');
            municipio.replaceChildren(new Option('Selecciona un municipio', ''));
            data.municipios.forEach(m => municipio.add(new Option(m.nombre, String(m.id))));
            municipio.disabled = false;
        } catch (e) { if (seq === revision) { municipio.replaceChildren(new Option('Vuelve a seleccionar el estado', '')); toast(e.message, true); } }
    });
    const copy = document.querySelector('[data-inscripcion-copiar]');
    if (copy) copy.addEventListener('click', async () => {
        const input = document.querySelector('[data-inscripcion-enlace]');
        try { await navigator.clipboard.writeText(input.value); toast('Enlace copiado correctamente.', false); }
        catch (_) { input.focus(); input.select(); toast('Copia el enlace seleccionado.', false); }
    });
    let busy = false;
    form.addEventListener('submit', async e => {
        e.preventDefault(); if (busy) return;
        let invalid = false;
        Array.from(form.elements).forEach(input => {
            if (!input.disabled && input.willValidate && !input.checkValidity()) {
                errorFor(input.name, input.validity.valueMissing ? 'Completa este campo obligatorio.' : 'Revisa el formato de este dato.'); invalid = true;
            }
        });
        if (field('municipio').disabled || !field('municipio').value) { errorFor('municipio', 'Selecciona un municipio del estado elegido.'); invalid = true; }
        ['correo','movil'].forEach(name => {
            if (field(name).value.trim().toLowerCase() !== field(name + '_confirmacion').value.trim().toLowerCase()) {
                errorFor(name + '_confirmacion', 'Los dos datos deben coincidir.'); invalid = true;
            }
        });
        const file = field('comprobante').files[0];
        if (file && file.size > 8 * 1024 * 1024) { errorFor('comprobante', 'El comprobante supera 8 MB.'); invalid = true; }
        if (invalid) { toast('Revisa los campos señalados antes de enviar.', true); form.querySelector('.is-invalid')?.focus(); return; }
        busy = true; const button = form.querySelector('[type=submit]'); button.disabled = true; state.textContent = 'Guardando inscripción…';
        try {
            const response = await fetch(form.action, {method:'POST',body:new FormData(form), credentials:'same-origin',headers:{'X-Requested-With':'fetch'}});
            const data = await response.json();
            if (!response.ok || !data.ok) {
                Object.entries(data.errores || {}).forEach(([name,text]) => errorFor(name,text));
                throw new Error(data.mensaje || 'No se pudo guardar la inscripción.');
            }
            state.textContent = 'Inscripción guardada correctamente. El comprobante queda pendiente de revisión.';
            toast('Inscripción registrada correctamente.', false);
            // Mantener el mismo token para reintentos; no producir otra inscripción con doble clic.
            form.querySelectorAll('input,select,button').forEach(input => { input.disabled = true; });
        } catch (error) { state.textContent = ''; toast(error.message || 'No se pudo completar el envío. Intenta nuevamente.', true); button.disabled = false; form.querySelector('.is-invalid')?.focus(); }
        finally { busy = false; }
    });
}());
