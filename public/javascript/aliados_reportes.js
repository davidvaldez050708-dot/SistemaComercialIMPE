document.addEventListener('DOMContentLoaded', function () {
    'use strict';

    const stateSelect = document.getElementById('reporte_aliados_estado');
    const municipalitySelect = document.getElementById('reporte_aliados_municipio');

    if (!stateSelect || !municipalitySelect) {
        return;
    }

    const municipalityOptions = Array.from(
        municipalitySelect.querySelectorAll('option[data-estado-id]')
    );

    const syncMunicipalities = function () {
        const stateId = Number(stateSelect.value || 0);
        const currentValue = String(municipalitySelect.value || '0');
        let currentStillAvailable = currentValue === '0';

        municipalityOptions.forEach(function (option) {
            const optionStateId = Number(option.dataset.estadoId || 0);
            const visible = stateId > 0 && optionStateId === stateId;

            option.hidden = !visible;
            option.disabled = !visible;

            if (visible && option.value === currentValue) {
                currentStillAvailable = true;
            }
        });

        municipalitySelect.disabled = stateId <= 0;

        const defaultOption = municipalitySelect.querySelector(
            'option[value="0"]'
        );
        if (defaultOption) {
            defaultOption.textContent =
                stateId > 0 ? 'Todos' : 'Selecciona un estado primero';
        }

        if (stateId <= 0 || !currentStillAvailable) {
            municipalitySelect.value = '0';
        }
    };

    const periodSelect = document.getElementById(
        'reporte_aliados_periodo'
    );
    const customPeriod = document.querySelector(
        '[data-aliados-custom-period]'
    );
    const dateFrom = document.getElementById(
        'reporte_aliados_fecha_desde'
    );
    const dateTo = document.getElementById(
        'reporte_aliados_fecha_hasta'
    );

    const syncPeriod = function () {
        if (!periodSelect || !customPeriod) {
            return;
        }

        const custom = periodSelect.value === 'personalizado';
        customPeriod.classList.toggle('d-none', !custom);

        if (dateFrom) {
            dateFrom.required = custom;
        }

        if (dateTo) {
            dateTo.required = custom;
        }
    };

    stateSelect.addEventListener('change', syncMunicipalities);
    periodSelect?.addEventListener('change', syncPeriod);

    syncMunicipalities();
    syncPeriod();
});
