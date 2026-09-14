(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        const instalarEstilosAcciones = function () {
            if (document.getElementById('seguimientoAccionesCompactas')) {
                return;
            }

            const estilo = document.createElement('style');
            estilo.id = 'seguimientoAccionesCompactas';
            estilo.textContent =
                '[data-linkage-follow-row] .table-actions{' +
                    'display:inline-flex;' +
                    'align-items:center;' +
                    'justify-content:flex-end;' +
                    'gap:6px;' +
                    'white-space:nowrap;' +
                '}' +
                '[data-linkage-follow-row] .table-actions .linkage-icon-action{' +
                    'width:38px!important;' +
                    'min-width:38px!important;' +
                    'max-width:38px!important;' +
                    'height:38px!important;' +
                    'padding:0!important;' +
                    'display:inline-flex!important;' +
                    'align-items:center;' +
                    'justify-content:center;' +
                    'gap:0!important;' +
                '}' +
                '[data-linkage-follow-row] .table-actions .linkage-icon-action i{' +
                    'margin:0!important;' +
                    'font-size:15px;' +
                    'line-height:1;' +
                '}';
            document.head.appendChild(estilo);
        };

        const compactarBotonTrabajo = function (botonTrabajo) {
            if (!botonTrabajo) {
                return;
            }

            const esAdministrador = Number(window.IMPE_CURRENT_ROLE_ID || 0) === 1;
            const etiqueta = esAdministrador
                ? 'Consultar panel de seguimiento'
                : 'Abrir panel de trabajo';

            botonTrabajo.classList.add('linkage-icon-action');
            botonTrabajo.title = etiqueta;
            botonTrabajo.setAttribute('aria-label', etiqueta);
            botonTrabajo.querySelector('span')?.remove();
        };

        const agregarAccesosExpediente = function () {
            document
                .querySelectorAll('[data-linkage-follow-row] [data-work-follow-id]')
                .forEach(function (botonTrabajo) {
                    compactarBotonTrabajo(botonTrabajo);

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
                    enlace.className =
                        'btn btn-system-light linkage-manage-button linkage-icon-action';
                    enlace.title = 'Ver expediente completo';
                    enlace.setAttribute('aria-label', 'Ver expediente completo');
                    enlace.setAttribute('data-direct-expedient', '');
                    enlace.innerHTML = '<i class="bi bi-folder2-open"></i>';

                    acciones.appendChild(enlace);
                });
        };

        instalarEstilosAcciones();

        // La tabla ofrece dos acciones compactas y distintas: el primer icono
        // abre el panel lateral de trabajo y la carpeta abre el expediente.
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
        // de registrar el listener que convierte el botón de trabajo en el
        // offcanvas del panel lateral.
        window.setTimeout(function () {
            buscarYAbrir(0);
        }, 0);
    });
})();
