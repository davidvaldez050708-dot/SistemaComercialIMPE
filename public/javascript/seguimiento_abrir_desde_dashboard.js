(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        const parametros = new URLSearchParams(window.location.search);
        const seguimientoId = Number(parametros.get('abrir_seguimiento') || 0);

        if (seguimientoId <= 0) {
            return;
        }

        const buscarYAbrir = function (intento) {
            const boton = document.querySelector(
                '[data-work-follow-id="' + seguimientoId + '"]'
            );

            if (boton) {
                boton.click();

                const url = new URL(window.location.href);
                url.searchParams.delete('abrir_seguimiento');
                window.history.replaceState({}, '', url.toString());
                return;
            }

            if (intento < 20) {
                window.setTimeout(function () {
                    buscarYAbrir(intento + 1);
                }, 100);
            }
        };

        buscarYAbrir(0);
    });
})();
