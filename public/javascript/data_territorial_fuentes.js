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
            enlacePobrezaMultidimensional.href = 'https://www.inegi.org.mx/contenidos/desarrollosocial/pm/tabulados/pm_ct_2024.xlsx';
            enlacePobrezaMultidimensional.textContent = 'Descargar XLSX oficial 2024';
            enlacePobrezaMultidimensional.title = 'Descarga directa del tabulado por entidad federativa publicado por INEGI';

            const enlaceTabulados = document.createElement('a');
            enlaceTabulados.href = 'https://www.inegi.org.mx/desarrollosocial/pm/#tabulados';
            enlaceTabulados.target = '_blank';
            enlaceTabulados.rel = 'noopener noreferrer';
            enlaceTabulados.textContent = 'Ver tabulados en INEGI';

            enlacePobrezaMultidimensional.insertAdjacentText('afterend', ' · ');
            enlacePobrezaMultidimensional.nextSibling.insertAdjacentElement?.('afterend', enlaceTabulados);

            if (!enlaceTabulados.parentNode) {
                enlacePobrezaMultidimensional.parentNode?.append(' · ', enlaceTabulados);
            }
        }
    });
})();
