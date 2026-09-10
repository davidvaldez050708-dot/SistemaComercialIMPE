(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        const offcanvas = document.getElementById('offcanvasSeguimientoTrabajo');

        if (!offcanvas) {
            return;
        }

        /*
         * La ruta puede conservarse en sessionStorage para evitar parpadeos,
         * pero no debe reutilizarse durante varios minutos en un flujo operativo.
         * Para el panel solamente aceptamos una ruta calculada hace pocos segundos;
         * después de eso la consulta real vuelve a ser la fuente autoritativa.
         */
        const cacheRuta = window.IMPE_SEGUIMIENTO_RUTA_CACHE;
        const obtenerCacheOriginal = typeof cacheRuta?.obtener === 'function'
            ? cacheRuta.obtener.bind(cacheRuta)
            : null;
        const edadCacheOriginal = typeof cacheRuta?.edad === 'function'
            ? cacheRuta.edad.bind(cacheRuta)
            : null;
        const MAX_EDAD_RUTA_PANEL_MS = 3000;

        if (cacheRuta && obtenerCacheOriginal && edadCacheOriginal) {
            cacheRuta.obtener = function (seguimientoId) {
                const edadOriginal = edadCacheOriginal(seguimientoId);

                if (edadOriginal === null || edadOriginal === undefined) {
                    return null;
                }

                const edad = Number(edadOriginal);
                if (!Number.isFinite(edad) || edad > MAX_EDAD_RUTA_PANEL_MS) {
                    return null;
                }

                return obtenerCacheOriginal(seguimientoId);
            };
        }

        const proximaAccion = offcanvas.querySelector('[data-work-next-action]');
        let restaurando = false;

        const tituloRutaActual = function () {
            return String(offcanvas.dataset.flowTitle || '').trim();
        };

        const restaurarProximaAccion = function () {
            if (!proximaAccion || restaurando) {
                return;
            }

            const titulo = tituloRutaActual();
            if (titulo === '') {
                return;
            }

            const actual = String(proximaAccion.textContent || '').trim();
            if (actual === titulo) {
                return;
            }

            restaurando = true;
            proximaAccion.textContent = titulo;
            window.queueMicrotask(function () {
                restaurando = false;
            });
        };

        if (proximaAccion && window.MutationObserver) {
            const observador = new MutationObserver(restaurarProximaAccion);
            observador.observe(proximaAccion, {
                childList: true,
                characterData: true,
                subtree: true
            });
        }

        /*
         * seguimiento_flujo.js emite este evento únicamente después de recibir
         * el estado calculado por los servicios de ruta. Desde ese momento el
         * título de la ruta manda sobre proxima_accion_texto del registro base.
         */
        document.addEventListener('impe:flow-updated', function (evento) {
            const detalle = evento.detail || {};
            const seguimientoId = Number(detalle.seguimientoId || 0);
            const titulo = String(detalle.titulo || '').trim();
            const seguimientoVisible = Number(offcanvas.dataset.flowSeguimientoId || 0);

            if (
                seguimientoId <= 0 ||
                titulo === '' ||
                (seguimientoVisible > 0 && seguimientoVisible !== seguimientoId)
            ) {
                return;
            }

            offcanvas.dataset.flowTitle = titulo;

            if (proximaAccion) {
                proximaAccion.textContent = titulo;
            }
        });

        /*
         * Al iniciar otro seguimiento eliminamos cualquier título autoritativo
         * del registro anterior. El manejador de carga existente mostrará
         * "Consultando ruta..." hasta que llegue el estado correcto.
         */
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

            delete offcanvas.dataset.flowTitle;
            delete offcanvas.dataset.flowStep;

            if (seguimientoId > 0) {
                offcanvas.dataset.flowSeguimientoId = String(seguimientoId);
            }
        }, true);

        offcanvas.addEventListener('hidden.bs.offcanvas', function () {
            delete offcanvas.dataset.flowTitle;
            delete offcanvas.dataset.flowStep;
        });
    });
})();
