(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        const offcanvas = document.getElementById('offcanvasSeguimientoTrabajo');

        if (!offcanvas) {
            return;
        }

        const urlEstadoOficio = 'index.php?controller=oficioVinculacion&action=estado';
        let seguimientoActualId = 0;
        let oficioEnviadoId = 0;
        let consultandoOficio = false;

        const normalizar = function (valor) {
            return String(valor || '').trim().toLowerCase();
        };

        const obtenerSeguimientoId = function () {
            const desdeFlujo = Number(offcanvas.dataset.flowSeguimientoId || 0);
            return desdeFlujo > 0 ? desdeFlujo : seguimientoActualId;
        };

        const corregirTituloRuta = function () {
            const pasoActual = Number(offcanvas.dataset.flowStep || 0);
            const tituloActual = String(offcanvas.dataset.flowTitle || '').trim();

            if (
                pasoActual !== 12 ||
                !normalizar(tituloActual).includes('reunión programada')
            ) {
                return;
            }

            const nodoActual = offcanvas.querySelector(
                '[data-flow-window] .linkage-flow-node.is-current strong'
            );

            if (nodoActual && nodoActual.textContent.trim() !== tituloActual) {
                nodoActual.textContent = tituloActual;
            }
        };

        const aplicarEstadoOficioEnviado = function () {
            const seguimientoId = obtenerSeguimientoId();

            if (seguimientoId <= 0 || seguimientoId !== oficioEnviadoId) {
                return;
            }

            const status = offcanvas.querySelector('[data-work-oficio-status]');
            if (status && status.textContent.trim() !== 'Correo enviado') {
                status.textContent = 'Correo enviado';
            }

            const botonCorreo = offcanvas.querySelector('[data-work-mail-oficio]');
            if (
                botonCorreo &&
                normalizar(botonCorreo.textContent) !== 'ver correo enviado'
            ) {
                botonCorreo.innerHTML =
                    '<i class="bi bi-envelope-paper me-1"></i>Ver correo enviado';
            }
        };

        const sincronizarVisual = function () {
            corregirTituloRuta();
            aplicarEstadoOficioEnviado();
        };

        const consultarEstadoOficio = async function () {
            const seguimientoId = obtenerSeguimientoId();

            if (seguimientoId <= 0 || consultandoOficio) {
                return;
            }

            consultandoOficio = true;

            try {
                const respuesta = await fetch(
                    urlEstadoOficio + '&seguimiento_id=' + encodeURIComponent(seguimientoId),
                    {
                        headers: { 'X-Requested-With': 'fetch' },
                        cache: 'no-store'
                    }
                );
                const datos = await respuesta.json();

                if (!respuesta.ok || !datos.ok || !datos.estado) {
                    return;
                }

                const estadoOficio = String(datos.estado.estado_oficio || '')
                    .trim()
                    .toUpperCase();

                oficioEnviadoId = estadoOficio === 'ENVIADO'
                    ? seguimientoId
                    : 0;

                sincronizarVisual();
            } catch (error) {
                console.error('No fue posible sincronizar el estado visual del oficio.', error);
            } finally {
                consultandoOficio = false;
            }
        };

        document.addEventListener('click', function (event) {
            const botonTrabajo = event.target.closest('[data-work-follow]');

            if (!botonTrabajo) {
                return;
            }

            seguimientoActualId = Number(
                botonTrabajo.getAttribute('data-work-follow-id') || 0
            );
            oficioEnviadoId = 0;

            window.setTimeout(function () {
                sincronizarVisual();
                consultarEstadoOficio();
            }, 180);
        }, true);

        document.addEventListener('impe:flow-updated', function (event) {
            const idEvento = Number(event.detail?.seguimientoId || 0);

            if (idEvento > 0) {
                seguimientoActualId = idEvento;
            }

            sincronizarVisual();
            consultarEstadoOficio();
        });

        const observer = new MutationObserver(function () {
            window.requestAnimationFrame(sincronizarVisual);
        });

        observer.observe(offcanvas, {
            childList: true,
            subtree: true,
            characterData: true
        });

        offcanvas.addEventListener('shown.bs.offcanvas', function () {
            sincronizarVisual();
            consultarEstadoOficio();
        });

        offcanvas.addEventListener('hidden.bs.offcanvas', function () {
            seguimientoActualId = 0;
            oficioEnviadoId = 0;
        });
    });
})();
