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

            if (!boton || boton.disabled) {
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
