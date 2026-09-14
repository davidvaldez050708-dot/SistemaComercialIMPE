(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        const enlacePobrezaLaboral = document.querySelector(
            '[data-power-import] .data-power-import-help a[href*="/desarrollosocial/pl/"]'
        );
        const enlacePobrezaMultidimensional = document.querySelector(
            '[data-education-import] .data-power-import-help a[href*="/desarrollosocial/pm/"]'
        );

        if (enlacePobrezaLaboral) {
            enlacePobrezaLaboral.href = 'https://www.inegi.org.mx/desarrollosocial/pl/#tabulados';
            enlacePobrezaLaboral.textContent = 'Ir directo a Tabulados de Pobreza Laboral';
            enlacePobrezaLaboral.title = 'Abre directamente la sección de Tabulados de INEGI para descargar el XLSX más reciente';
        }

        if (enlacePobrezaMultidimensional) {
            enlacePobrezaMultidimensional.href =
                'https://www.inegi.org.mx/contenidos/desarrollosocial/pm/tabulados/pm_ct_2024.xlsx';
            enlacePobrezaMultidimensional.innerHTML =
                '<i class="bi bi-download" aria-hidden="true"></i> Descargar XLSX oficial 2024';
            enlacePobrezaMultidimensional.title =
                'Descarga directamente el tabulado oficial por entidad federativa de INEGI';
            enlacePobrezaMultidimensional.setAttribute('download', 'pm_ct_2024.xlsx');
            enlacePobrezaMultidimensional.removeAttribute('target');
        }
    });
})();
