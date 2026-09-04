(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        const offcanvas = document.getElementById('offcanvasSeguimientoTrabajo');

        if (!offcanvas) {
            return;
        }

        const aplicarBloqueo = function () {
            const boton = offcanvas.querySelector(
                '[data-work-flow-section] [data-flow-action="REUNION_AUN_NO_DISPONIBLE"]'
            );

            if (!boton) {
                return;
            }

            boton.disabled = true;
            boton.setAttribute('aria-disabled', 'true');
            boton.setAttribute(
                'title',
                'La reunión todavía no ha ocurrido.'
            );
            boton.classList.add('disabled');
        };

        const observador = new MutationObserver(aplicarBloqueo);
        observador.observe(offcanvas, {
            childList: true,
            subtree: true,
            attributes: true
        });

        aplicarBloqueo();
    });
})();
