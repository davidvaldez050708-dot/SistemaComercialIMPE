(function () {
    'use strict';

    /*
     * La tabla llega inicialmente renderizada por PHP con el estado interno
     * (NUEVO, CONTACTANDO, etc.) y después la bandeja consulta la ruta real.
     * Ocultamos únicamente la etiqueta de etapa mientras dura esa sincronización
     * para evitar que el usuario vea por milisegundos un estado técnico distinto.
     * visibility:hidden conserva el espacio y evita saltos en la tabla.
     */
    const estiloSincronizacionEtapa = document.createElement('style');
    estiloSincronizacionEtapa.textContent =
        '[data-linkage-follow-row] [data-row-stage-label]{visibility:hidden;}' +
        '[data-linkage-follow-row] [data-row-stage-label][data-route-stage-ready="1"]{visibility:visible;}';
    document.head.appendChild(estiloSincronizacionEtapa);

    document.addEventListener('DOMContentLoaded', function () {
        const offcanvas = document.getElementById('offcanvasSeguimientoTrabajo');

        if (!offcanvas) {
            return;
        }

        const rolId = Number(window.IMPE_CURRENT_ROLE_ID || 0);
        const esAdministrador = rolId === 1;
        const pasosRuta = [
            [1, 'Seguimiento iniciado'],
            [2, 'Investigación de datos'],
            [3, 'Contacto y validación'],
            [4, 'Datos verificados'],
            [5, 'Oficio preparado'],
            [6, 'PDF generado'],
            [7, 'Oficio / correo enviado'],
            [8, 'Esperando respuesta'],
            [9, 'Respuesta recibida'],
            [10, 'Seguimiento por correo'],
            [11, 'Reunión agendada'],
            [12, 'Reunión y acuerdos'],
            [13, 'Convenio']
        ];
        const observadoresEtapa = new WeakMap();

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

        const etiquetaEtapaRuta = function (pasoActual, tituloFlujo, fila) {
            const estadoInterno = String(
                fila?.dataset.internalStage || fila?.dataset.stage || ''
            ).trim().toUpperCase();
            const titulo = String(tituloFlujo || '').trim().toLowerCase();

            if (estadoInterno === 'DESCARTADO') {
                return 'Descartado';
            }

            if (Number(pasoActual) === 12) {
                if (titulo.includes('programad')) {
                    return 'Reunión programada';
                }

                if (
                    titulo.includes('seguimiento de acuerdos') ||
                    titulo.includes('dar seguimiento')
                ) {
                    return 'Seguimiento de acuerdos';
                }

                return 'Reunión y acuerdos';
            }

            const paso = pasosRuta.find(function (item) {
                return Number(item[0]) === Number(pasoActual);
            });

            return paso ? paso[1] : '';
        };

        const fijarEtiquetaRuta = function (fila, pasoActual, tituloFlujo) {
            if (!fila || Number(pasoActual) <= 0) {
                return;
            }

            const etapa = fila.querySelector('[data-row-stage-label]');
            const etiqueta = etiquetaEtapaRuta(pasoActual, tituloFlujo, fila);

            if (!etapa || etiqueta === '') {
                return;
            }

            fila.dataset.flowStep = String(Number(pasoActual));
            fila.dataset.flowStageLabel = etiqueta;

            if (String(tituloFlujo || '').trim() !== '') {
                fila.dataset.flowTitle = String(tituloFlujo).trim();
            }

            etapa.textContent = etiqueta;
            etapa.title = 'Paso ' + Number(pasoActual) + ' de 13';
            etapa.dataset.routeStageReady = '1';
        };

        const protegerEtiquetaRuta = function (fila) {
            const etapa = fila?.querySelector('[data-row-stage-label]');

            if (!etapa || observadoresEtapa.has(etapa) || !window.MutationObserver) {
                return;
            }

            const observador = new MutationObserver(function () {
                const etiquetaAutoritativa = String(
                    fila.dataset.flowStageLabel || ''
                ).trim();
                const actual = String(etapa.textContent || '').trim();

                if (etiquetaAutoritativa === '' || actual === etiquetaAutoritativa) {
                    return;
                }

                observador.disconnect();
                etapa.textContent = etiquetaAutoritativa;

                const paso = Number(fila.dataset.flowStep || 0);
                if (paso > 0) {
                    etapa.title = 'Paso ' + paso + ' de 13';
                }
                etapa.dataset.routeStageReady = '1';

                observador.observe(etapa, {
                    childList: true,
                    characterData: true,
                    subtree: true
                });
            });

            observador.observe(etapa, {
                childList: true,
                characterData: true,
                subtree: true
            });
            observadoresEtapa.set(etapa, observador);
        };

        const reaplicarEtiquetasRuta = function () {
            document.querySelectorAll('[data-linkage-follow-row]').forEach(function (fila) {
                protegerEtiquetaRuta(fila);

                const paso = Number(fila.dataset.flowStep || 0);
                const titulo = String(fila.dataset.flowTitle || '');

                if (paso > 0) {
                    fijarEtiquetaRuta(fila, paso, titulo);
                }
            });
        };

        const actualizarDesdeEvento = function (evento) {
            const detalle = evento.detail || {};
            const seguimientoId = Number(detalle.seguimientoId || 0);
            const pasoActual = Number(detalle.pasoActual || 0);

            if (seguimientoId <= 0 || pasoActual <= 0) {
                return;
            }

            const boton = document.querySelector(
                '[data-work-follow-id="' + seguimientoId + '"]'
            );
            const fila = boton?.closest('[data-linkage-follow-row]');

            if (!fila) {
                return;
            }

            protegerEtiquetaRuta(fila);
            fijarEtiquetaRuta(
                fila,
                pasoActual,
                String(detalle.titulo || fila.dataset.flowTitle || '')
            );
        };

        // La ruta completa ya se muestra en seguimiento_flujo.js.
        // Este archivo conserva el modo de consulta del Administrador y evita
        // que el panel de trabajo reemplace la etapa de ruta por el estado
        // interno de base de datos (por ejemplo, "Nuevo").
        offcanvas.querySelector('[data-work-route-card]')?.remove();
        aplicarModoAdministrador();
        reaplicarEtiquetasRuta();

        document.addEventListener('impe:flow-row-updated', actualizarDesdeEvento);
        document.addEventListener('impe:flow-updated', actualizarDesdeEvento);

        offcanvas.addEventListener('shown.bs.offcanvas', function () {
            offcanvas.querySelector('[data-work-route-card]')?.remove();
            aplicarModoAdministrador();
            window.setTimeout(reaplicarEtiquetasRuta, 0);
            window.setTimeout(reaplicarEtiquetasRuta, 350);
        });

        offcanvas.addEventListener('hidden.bs.offcanvas', function () {
            window.setTimeout(reaplicarEtiquetasRuta, 0);
        });

        // Si por algún problema no responde la consulta de ruta, después de unos
        // segundos mostramos el valor renderizado por PHP para no dejar la celda vacía.
        window.setTimeout(function () {
            document
                .querySelectorAll('[data-linkage-follow-row] [data-row-stage-label]:not([data-route-stage-ready="1"])')
                .forEach(function (etapa) {
                    etapa.dataset.routeStageReady = '1';
                });
        }, 5000);
    });
})();
