(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        const agregarAccesosExpediente = function () {
            document
                .querySelectorAll('[data-linkage-follow-row] [data-work-follow-id]')
                .forEach(function (botonTrabajo) {
                    const acciones = botonTrabajo.closest('.table-actions');
                    const seguimientoId = Number(
                        botonTrabajo.getAttribute('data-work-follow-id') || 0
                    );
                    const href = String(
                        botonTrabajo.getAttribute('href') || ''
                    ).trim();

                    if (
                        !acciones ||
                        seguimientoId <= 0 ||
                        href === '' ||
                        acciones.querySelector('[data-direct-expedient]')
                    ) {
                        return;
                    }

                    const enlace = document.createElement('a');
                    enlace.href = href;
                    enlace.className = 'btn btn-system-light linkage-manage-button';
                    enlace.title = 'Ver expediente completo';
                    enlace.setAttribute('aria-label', 'Ver expediente completo');
                    enlace.setAttribute('data-direct-expedient', '');
                    enlace.innerHTML =
                        '<i class="bi bi-folder2-open"></i>' +
                        '<span>Expediente</span>';

                    acciones.appendChild(enlace);
                });
        };

        // La tabla ofrece dos acciones distintas: Trabajar abre el panel lateral
        // y Expediente navega directamente al historial completo del seguimiento.
        agregarAccesosExpediente();

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

        // Esperamos al siguiente ciclo para que la vista de Seguimiento termine
        // de registrar el listener que convierte el botón "Trabajar" en el
        // offcanvas del panel de trabajo. Si se hacía clic durante el propio
        // DOMContentLoaded, el enlace podía navegar al expediente completo.
        window.setTimeout(function () {
            buscarYAbrir(0);
        }, 0);
    });
})();
