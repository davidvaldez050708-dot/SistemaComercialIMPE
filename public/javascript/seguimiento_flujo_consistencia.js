(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        const offcanvas = document.getElementById('offcanvasSeguimientoTrabajo');

        if (!offcanvas) {
            return;
        }

        const proximaAccion = offcanvas.querySelector('[data-work-next-action]');
        let seguimientoActivoId = 0;
        let restaurando = false;

        const filaSeguimiento = function (seguimientoId) {
            return document
                .querySelector('[data-work-follow-id="' + Number(seguimientoId || 0) + '"]')
                ?.closest('[data-linkage-follow-row]') || null;
        };

        const flujoGuardado = function (seguimientoId) {
            const cacheRuta = window.IMPE_SEGUIMIENTO_RUTA_CACHE;

            if (!cacheRuta || typeof cacheRuta.obtener !== 'function') {
                return null;
            }

            try {
                return cacheRuta.obtener(Number(seguimientoId || 0)) || null;
            } catch (error) {
                return null;
            }
        };

        const tituloRutaAutoritativo = function (seguimientoId) {
            seguimientoId = Number(
                seguimientoId ||
                seguimientoActivoId ||
                offcanvas.dataset.flowSeguimientoId ||
                0
            );

            if (seguimientoId <= 0) {
                return '';
            }

            const seguimientoOffcanvas = Number(offcanvas.dataset.flowSeguimientoId || 0);
            const tituloOffcanvas = String(offcanvas.dataset.flowTitle || '').trim();

            if (seguimientoOffcanvas === seguimientoId && tituloOffcanvas !== '') {
                return tituloOffcanvas;
            }

            const fila = filaSeguimiento(seguimientoId);
            const tituloFila = String(fila?.dataset.flowTitle || '').trim();

            if (tituloFila !== '') {
                return tituloFila;
            }

            return String(flujoGuardado(seguimientoId)?.titulo || '').trim();
        };

        const fijarProximaAccion = function (seguimientoId, tituloExplicito) {
            seguimientoId = Number(seguimientoId || seguimientoActivoId || 0);

            if (
                seguimientoId <= 0 ||
                (seguimientoActivoId > 0 && seguimientoId !== seguimientoActivoId)
            ) {
                return false;
            }

            const titulo = String(
                tituloExplicito || tituloRutaAutoritativo(seguimientoId) || ''
            ).trim();

            if (titulo === '') {
                return false;
            }

            offcanvas.dataset.flowSeguimientoId = String(seguimientoId);
            offcanvas.dataset.flowTitle = titulo;

            if (!proximaAccion || String(proximaAccion.textContent || '').trim() === titulo) {
                return true;
            }

            restaurando = true;
            proximaAccion.textContent = titulo;
            window.queueMicrotask(function () {
                restaurando = false;
            });

            return true;
        };

        if (proximaAccion && window.MutationObserver) {
            const observador = new MutationObserver(function () {
                if (restaurando || seguimientoActivoId <= 0) {
                    return;
                }

                const titulo = tituloRutaAutoritativo(seguimientoActivoId);
                const actual = String(proximaAccion.textContent || '').trim();

                if (titulo === '' || actual === titulo) {
                    return;
                }

                fijarProximaAccion(seguimientoActivoId, titulo);
            });

            observador.observe(proximaAccion, {
                childList: true,
                characterData: true,
                subtree: true
            });
        }

        const actualizarDesdeRuta = function (evento) {
            const detalle = evento.detail || {};
            const seguimientoId = Number(detalle.seguimientoId || 0);
            const titulo = String(detalle.titulo || '').trim();

            if (
                seguimientoId <= 0 ||
                titulo === '' ||
                seguimientoActivoId <= 0 ||
                seguimientoId !== seguimientoActivoId
            ) {
                return;
            }

            fijarProximaAccion(seguimientoId, titulo);
        };

        document.addEventListener('impe:flow-updated', actualizarDesdeRuta);
        document.addEventListener('impe:flow-row-updated', actualizarDesdeRuta);

        document.addEventListener('click', function (event) {
            const boton = event.target instanceof Element
                ? event.target.closest('[data-work-follow]')
                : null;

            if (!boton) {
                return;
            }

            const seguimientoId = Number(
                boton.getAttribute('data-work-follow-id') || 0
            );

            if (seguimientoId <= 0) {
                return;
            }

            const seguimientoAnterior = Number(offcanvas.dataset.flowSeguimientoId || 0);

            if (seguimientoAnterior > 0 && seguimientoAnterior !== seguimientoId) {
                delete offcanvas.dataset.flowTitle;
                delete offcanvas.dataset.flowStep;
            }

            seguimientoActivoId = seguimientoId;
            offcanvas.dataset.flowSeguimientoId = String(seguimientoId);

            const flujo = flujoGuardado(seguimientoId);
            const titulo = tituloRutaAutoritativo(seguimientoId);

            if (flujo && window.IMPE_SEGUIMIENTO_RUTA_CACHE?.renderizarPanel) {
                window.IMPE_SEGUIMIENTO_RUTA_CACHE.renderizarPanel(
                    seguimientoId,
                    flujo,
                    false
                );
            }

            if (!fijarProximaAccion(seguimientoId, titulo) && proximaAccion) {
                proximaAccion.textContent = 'Consultando ruta...';
            }
        }, true);

        offcanvas.addEventListener('shown.bs.offcanvas', function () {
            const seguimientoId = Number(
                seguimientoActivoId || offcanvas.dataset.flowSeguimientoId || 0
            );

            if (seguimientoId > 0) {
                fijarProximaAccion(seguimientoId);
            }
        });

        offcanvas.addEventListener('hidden.bs.offcanvas', function () {
            seguimientoActivoId = 0;
        });
    });
})();
