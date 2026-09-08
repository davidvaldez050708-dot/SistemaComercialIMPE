(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        const offcanvas = document.getElementById('offcanvasSeguimientoTrabajo');

        if (!offcanvas) {
            return;
        }

        const aplicarBloqueo = function () {
            const botones = offcanvas.querySelectorAll(
                '[data-work-flow-section] [data-flow-action="REUNION_AUN_NO_DISPONIBLE"], ' +
                '[data-work-flow-section] [data-flow-action="SEGUIMIENTO_REUNION_AUN_NO_DISPONIBLE"]'
            );

            botones.forEach(function (boton) {
                if (boton.disabled) {
                    return;
                }

                const esSeguimiento = boton.getAttribute('data-flow-action') ===
                    'SEGUIMIENTO_REUNION_AUN_NO_DISPONIBLE';

                boton.disabled = true;
                boton.setAttribute('aria-disabled', 'true');
                boton.setAttribute(
                    'title',
                    esSeguimiento
                        ? 'El seguimiento de acuerdos todavía no llega a su fecha programada.'
                        : 'La reunión todavía no ha ocurrido.'
                );
                boton.classList.add('disabled');
            });
        };

        const observador = new MutationObserver(function () {
            aplicarBloqueo();
        });

        observador.observe(offcanvas, {
            childList: true,
            subtree: true
        });

        aplicarBloqueo();
    });
})();
