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
                'index.php?controller=fuenteOficial&action=rezagoEducativo';
            enlacePobrezaMultidimensional.innerHTML =
                '<i class="bi bi-download" aria-hidden="true"></i> Descargar XLSX oficial vigente';
            enlacePobrezaMultidimensional.title =
                'El sistema consulta INEGI y resuelve automáticamente el tabulado vigente de Pobreza Multidimensional';
            enlacePobrezaMultidimensional.removeAttribute('download');
            enlacePobrezaMultidimensional.removeAttribute('target');
        }
    });
})();
