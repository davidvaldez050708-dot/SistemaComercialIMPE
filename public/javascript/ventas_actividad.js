(function () {
    'use strict';
    function initialize() {
        document.querySelectorAll('[data-sales-activity-form]').forEach(function (form) {
            if (form.dataset.salesAutoInitialized) return;
            form.dataset.salesAutoInitialized = '1';
            const root = form.closest('.sales-activity-module') || form.parentElement;
            const period = form.querySelector('[data-sales-period]');
            const custom = form.querySelectorAll('[data-sales-custom]');
            const from = form.elements.namedItem('fecha_desde'), until = form.elements.namedItem('fecha_hasta');
            const note = root.querySelector('[data-sales-activity-status]');
            const exports = Array.from(root.querySelectorAll('[data-sales-report-pdf]'), link => ({link, hidden: link.hidden}));
            if (!period || !from || !until) return;
            let timer = 0, submitting = false, customDraft = period.value === 'personalizado';
            function customFields() {
                const enabled = period.value === 'personalizado';
                custom.forEach(block => {
                    block.hidden = !enabled;
                    block.querySelectorAll('input').forEach(input => { input.disabled = !enabled; input.required = enabled; });
                });
                from.setCustomValidity(''); until.setCustomValidity('');
            }
            const parameters = () => new URLSearchParams(new FormData(form)).toString();
            customFields();
            const applied = parameters();
            function status(message) {
                if (note) { note.textContent = message; note.hidden = !message; }
            }
            function valid() {
                from.setCustomValidity(''); until.setCustomValidity('');
                if (period.value === 'personalizado') {
                    if (!from.value || !until.value) return false;
                    if (from.value > until.value) from.setCustomValidity('La fecha inicial debe ser anterior o igual a la final.');
                    else if ((Date.parse(until.value + 'T00:00:00Z') - Date.parse(from.value + 'T00:00:00Z')) / 86400000 > 365)
                        until.setCustomValidity('Selecciona un periodo de hasta 366 días.');
                }
                return form.checkValidity();
            }
            function changed(delay) {
                clearTimeout(timer);
                if (submitting) return;
                const dirty = parameters() !== applied;
                exports.forEach(({link, hidden}) => { link.hidden = dirty || hidden; });
                if (!dirty) { status(''); return; }
                if (!valid()) {
                    status(!from.value || !until.value ? 'Selecciona las fechas desde y hasta para consultar el reporte.' : 'Revisa el rango: hasta 366 días, sin fechas futuras y con el inicio anterior al final.');
                    return;
                }
                status('');
                timer = window.setTimeout(() => { if (!submitting && valid() && parameters() !== applied) form.requestSubmit(); }, delay);
            }
            form.addEventListener('change', event => {
                if (event.target === period) {
                    // Los límites del periodo anterior no son un rango elegido por el usuario.
                    if (period.value === 'personalizado' && !customDraft) { from.value = ''; until.value = ''; }
                    customFields();
                }
                const date = event.target === from || event.target === until;
                if (date) customDraft = true;
                changed(date ? 600 : 300);
            });
            form.addEventListener('input', event => {
                if (event.target === from || event.target === until) { customDraft = true; changed(600); }
            });
            form.addEventListener('submit', event => {
                clearTimeout(timer);
                if (submitting || !valid() || parameters() === applied) { event.preventDefault(); return; }
                submitting = true;
                form.setAttribute('aria-busy', 'true');
                exports.forEach(({link}) => { link.hidden = true; });
                status('Consultando reporte…');
            });
            window.addEventListener('pagehide', () => clearTimeout(timer));
            window.addEventListener('pageshow', event => {
                if (!event.persisted) return;
                // Al volver con Atrás, los controles deben corresponder al corte de esa página.
                form.reset(); customFields(); customDraft = period.value === 'personalizado'; submitting = false;
                form.removeAttribute('aria-busy'); exports.forEach(({link, hidden}) => { link.hidden = hidden; }); status('');
            });
        });
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initialize, {once: true});
    else initialize();
})();
