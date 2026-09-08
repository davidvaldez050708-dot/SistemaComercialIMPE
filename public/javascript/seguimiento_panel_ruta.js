(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        const offcanvas = document.getElementById('offcanvasSeguimientoTrabajo');

        if (!offcanvas) {
            return;
        }

        const rolId = Number(window.IMPE_CURRENT_ROLE_ID || 0);
        const esAdministrador = rolId === 1;

        const aplicarModoAdministrador = function () {
            if (!esAdministrador) {
                return;
            }

            document.body.classList.add('impe-seguimiento-readonly');

            document.querySelectorAll('[data-work-follow]').forEach(function (boton) {
                boton.title = 'Consultar seguimiento';
                boton.setAttribute('aria-label', 'Consultar seguimiento');

                const icono = boton.querySelector('i');
                const texto = boton.querySelector('span');

                if (icono) {
                    icono.className = 'bi bi-eye';
                }

                if (texto) {
                    texto.textContent = 'Ver';
                }
            });

            const etiquetaPanel = offcanvas.querySelector('.linkage-work-header > div > span');
            if (etiquetaPanel) {
                etiquetaPanel.textContent = 'Vista de seguimiento';
            }
        };

        // La ruta completa ya se muestra en seguimiento_flujo.js.
        // Evitamos duplicar arriba la tarjeta "Etapa de ruta" y conservamos
        // aquí únicamente el comportamiento especial de consulta para Administrador.
        offcanvas.querySelector('[data-work-route-card]')?.remove();
        aplicarModoAdministrador();

        offcanvas.addEventListener('shown.bs.offcanvas', function () {
            offcanvas.querySelector('[data-work-route-card]')?.remove();
            aplicarModoAdministrador();
        });
    });
})();
