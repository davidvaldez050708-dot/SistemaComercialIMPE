(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        const root = document.querySelector('[data-analyst-report-modes]');
        const form = document.querySelector('[data-report-form]');
        const inputType = form?.querySelector('[data-report-type-input]');

        if (!root || !form || !inputType) {
            return;
        }

        const buttons = Array.from(root.querySelectorAll('[data-report-mode]'));
        const help = form.querySelector('[data-report-mode-help]');
        const institution = form.querySelector('#reporte_institucion');
        const labels = {
            actividad: {
                help: 'El periodo se aplica a las actividades e interacciones registradas por ti.',
                fields: ['periodo', 'territorio', 'actividad']
            },
            cartera: {
                help: 'Consulta el estado actual de tu cartera. Puedes acotar territorio, municipio, etapa o inactividad.',
                fields: ['territorio', 'municipio', 'institucion', 'estatus', 'actividad', 'inactividad']
            },
            institucion: {
                help: 'Selecciona una institución para consultar su expediente ejecutivo de seguimiento.',
                fields: ['territorio', 'municipio', 'institucion']
            }
        };

        const neutralValue = function (control) {
            if (!control) return;
            if (control.tagName === 'SELECT') {
                const preferred = Array.from(control.options).find(function (option) {
                    return option.value === '0' || option.value === '';
                });
                if (preferred) control.value = preferred.value;
            } else if (control.type === 'date') {
                control.value = '';
            }
        };

        const applyMode = function (mode, resetHidden) {
            if (!labels[mode]) {
                mode = 'cartera';
            }

            inputType.value = mode;
            const visibleFields = new Set(labels[mode].fields);

            buttons.forEach(function (button) {
                const active = button.dataset.reportMode === mode;
                button.classList.toggle('is-active', active);
                button.setAttribute('aria-pressed', active ? 'true' : 'false');
            });

            form.querySelectorAll('[data-report-field]').forEach(function (wrapper) {
                const field = String(wrapper.dataset.reportField || '');
                const visible = visibleFields.has(field) && field !== 'responsable';
                wrapper.classList.toggle('d-none', !visible);

                wrapper.querySelectorAll('input, select, textarea').forEach(function (control) {
                    if (!visible && resetHidden) {
                        neutralValue(control);
                    }
                    control.disabled = !visible;
                });
            });

            if (institution) {
                institution.required = mode === 'institucion';
            }

            const startLabel = form.querySelector('label[for="reporte_fecha_inicial"]');
            const endLabel = form.querySelector('label[for="reporte_fecha_final"]');
            if (startLabel) startLabel.textContent = mode === 'actividad' ? 'Actividad desde' : 'Fecha inicial';
            if (endLabel) endLabel.textContent = mode === 'actividad' ? 'Actividad hasta' : 'Fecha final';
            if (help) help.textContent = labels[mode].help;
        };

        buttons.forEach(function (button) {
            button.addEventListener('click', function () {
                applyMode(String(button.dataset.reportMode || ''), true);
            });
        });

        form.addEventListener('submit', function (event) {
            const mode = String(inputType.value || 'cartera');

            if (mode === 'institucion') {
                const value = String(institution?.value || '').trim();
                if (value === '' || value === '0') {
                    event.preventDefault();
                    institution?.setCustomValidity('Selecciona una institución para generar este reporte.');
                    institution?.reportValidity();
                    institution?.focus();
                    return;
                }
            }

            institution?.setCustomValidity('');
        });

        institution?.addEventListener('change', function () {
            institution.setCustomValidity('');
        });

        applyMode(String(inputType.value || 'cartera'), false);
    });
})();
