(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        const offcanvas = document.getElementById('offcanvasSeguimientoTrabajo');

        if (!offcanvas) {
            return;
        }

        const esAdministrador =
            Number(window.IMPE_CURRENT_ROLE_ID || 0) === 1;

        const pasosRuta = new Map([
            [1, 'Seguimiento iniciado'],
            [2, 'Investigación de datos'],
            [3, 'Contacto y validación'],
            [4, 'Verificación de datos'],
            [5, 'Preparación de oficio'],
            [6, 'Generación de PDF'],
            [7, 'Envío de oficio / correo'],
            [8, 'Esperando respuesta'],
            [9, 'Respuesta recibida'],
            [10, 'Seguimiento por correo'],
            [11, 'Reunión agendada'],
            [12, 'Reunión y acuerdos'],
            [13, 'Convenio']
        ]);

        const etiquetaRuta = function (detalle, fila) {
            if (Boolean(detalle?.esAliado)) {
                return 'Aliado';
            }

            const desdeFlujo = String(
                detalle?.flujo?.ventana?.actual?.titulo || ''
            ).trim();

            if (desdeFlujo !== '') {
                return desdeFlujo;
            }

            return pasosRuta.get(Number(detalle?.pasoActual || 0)) || '';
        };

        const filaPorSeguimiento = function (seguimientoId) {
            const boton = document.querySelector(
                '[data-work-follow-id="' +
                Number(seguimientoId || 0) +
                '"]'
            );

            return boton?.closest('[data-linkage-follow-row]') || null;
        };

        const actualizarFila = function (detalle) {
            const seguimientoId = Number(detalle?.seguimientoId || 0);
            const fila = filaPorSeguimiento(seguimientoId);

            if (!fila) {
                return;
            }

            const pasoActual = Number(detalle?.pasoActual || 0);
            const titulo = String(detalle?.titulo || '').trim();
            const esAliado = Boolean(detalle?.esAliado);
            const etapa = fila.querySelector('[data-row-stage-label]');
            const proxima = fila.querySelector('[data-row-next-action]');
            const etiqueta = etiquetaRuta(detalle, fila);

            if (pasoActual > 0) {
                fila.dataset.flowStep = String(pasoActual);
            }

            if (titulo !== '') {
                fila.dataset.flowTitle = titulo;
            }

            fila.dataset.ally = esAliado ? '1' : '0';
            fila.classList.toggle('is-ally', esAliado);

            if (etapa && etiqueta !== '') {
                fila.dataset.flowStageLabel = etiqueta;
                etapa.textContent = etiqueta;
                etapa.classList.toggle('is-ally', esAliado);
                etapa.dataset.routeStageReady = '1';
                etapa.title = esAliado
                    ? 'Convenio formalizado · Paso 13 de 13'
                    : (
                        pasoActual > 0
                            ? 'Paso ' + pasoActual + ' de 13'
                            : ''
                    );
            }

            if (proxima && titulo !== '') {
                const texto = esAliado
                    ? 'Sin acción pendiente'
                    : titulo;
                proxima.textContent = texto;
                proxima.dataset.flowNextAction = texto;
            }
        };

        const aplicarModoAdministrador = function () {
            if (!esAdministrador) {
                return;
            }

            document.body.classList.add('impe-seguimiento-readonly');

            document.querySelectorAll('[data-work-follow]').forEach(function (boton) {
                const fila = boton.closest('[data-linkage-follow-row]');
                const esAliado = String(fila?.dataset.ally || '') === '1';

                boton.title = esAliado
                    ? 'Consultar aliado'
                    : 'Consultar seguimiento';
                boton.setAttribute(
                    'aria-label',
                    esAliado
                        ? 'Consultar aliado'
                        : 'Consultar seguimiento'
                );

                const icono = boton.querySelector('i');
                const texto = boton.querySelector('span');

                if (icono) {
                    icono.className = 'bi bi-eye';
                }

                if (texto) {
                    texto.textContent = 'Ver';
                }
            });

            const etiquetaPanel = offcanvas.querySelector(
                '.linkage-work-header > div > span'
            );

            if (etiquetaPanel) {
                etiquetaPanel.textContent =
                    String(offcanvas.dataset.ally || '') === '1'
                        ? 'Expediente de aliado'
                        : 'Vista de seguimiento';
            }
        };

        /*
         * Las filas que PHP ya resolvió son válidas desde el primer render.
         * No se ocultan ni se protegen con observers: la única actualización
         * posterior llega por los eventos de la ruta autoritativa.
         */
        document
            .querySelectorAll('[data-linkage-follow-row] [data-row-stage-label]')
            .forEach(function (etapa) {
                etapa.dataset.routeStageReady = '1';
            });

        document.addEventListener('impe:flow-row-updated', function (event) {
            actualizarFila(event.detail || {});
            aplicarModoAdministrador();
        });

        document.addEventListener('impe:flow-updated', function (event) {
            actualizarFila(event.detail || {});
            aplicarModoAdministrador();
        });

        offcanvas.addEventListener('shown.bs.offcanvas', function () {
            aplicarModoAdministrador();
        });

        aplicarModoAdministrador();
    });
})();
