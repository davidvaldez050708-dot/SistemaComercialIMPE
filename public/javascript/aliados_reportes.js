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
            const visible = stateId === 0 || optionStateId === stateId;

            option.hidden = !visible;
            option.disabled = !visible;

            if (visible && option.value === currentValue) {
                currentStillAvailable = true;
            }
        });

        if (!currentStillAvailable) {
            municipalitySelect.value = '0';
        }
    };

    stateSelect.addEventListener('change', syncMunicipalities);
    syncMunicipalities();
});
