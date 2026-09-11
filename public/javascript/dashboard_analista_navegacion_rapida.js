(function () {
    'use strict';

    const obtenerSeguimientoDesdeHref = function (href) {
        try {
            const url = new URL(String(href || ''), window.location.href);
            return Number(
                url.searchParams.get('abrir_seguimiento') ||
                url.searchParams.get('trabajar_id') ||
                0
            );
        } catch (error) {
            return 0;
        }
    };

    const obtenerEstadoDesdeHref = function (href) {
        try {
            const url = new URL(String(href || ''), window.location.href);
            return Number(url.searchParams.get('estado_id') || 0);
        } catch (error) {
            return 0;
        }
    };

    const precargarRuta = async function (seguimientoId) {
        seguimientoId = Number(seguimientoId || 0);
        if (seguimientoId <= 0) {
            return;
        }

        try {
            if (window.IMPE_SEGUIMIENTO_RUTA_CACHE?.obtener?.(seguimientoId)) {
                return;
            }

            await fetch(
                'index.php?controller=seguimientoFlujo&action=estado&seguimiento_id=' +
                    encodeURIComponent(seguimientoId),
                {
                    headers: { 'X-Requested-With': 'fetch' },
                    credentials: 'same-origin',
                    cache: 'no-store'
                }
            );
        } catch (error) {
            // La navegación normal conserva su propio manejo de errores.
        }
    };

    const precargarSeguimiento = async function (seguimientoId) {
        seguimientoId = Number(seguimientoId || 0);
        if (seguimientoId <= 0) {
            return;
        }

        const tareas = [];

        try {
            const cachePanel = window.IMPE_SEGUIMIENTO_PANEL_CACHE;
            if (cachePanel?.precargar) {
                tareas.push(cachePanel.precargar(seguimientoId));
            }
        } catch (error) {
            // Continúa con la precarga de ruta.
        }

        tareas.push(precargarRuta(seguimientoId));
        await Promise.allSettled(tareas);
    };

    document.addEventListener('DOMContentLoaded', function () {
        const tablero = document.querySelector('[data-analyst-dashboard]');
        if (!tablero || Number(window.IMPE_CURRENT_ROLE_ID || 0) !== 4) {
            return;
        }

        const enlaces = Array.from(tablero.querySelectorAll(
            '.analyst-work-button, .analyst-upcoming-item'
        ));

        if (enlaces.length === 0) {
            return;
        }

        enlaces.forEach(function (enlace) {
            const seguimientoId = obtenerSeguimientoDesdeHref(enlace.getAttribute('href'));
            if (seguimientoId <= 0) {
                return;
            }

            enlace.dataset.dashboardSeguimientoId = String(seguimientoId);
            enlace.dataset.dashboardEstadoId = String(
                obtenerEstadoDesdeHref(enlace.getAttribute('href')) || 0
            );

            const adelantar = function () {
                void precargarSeguimiento(seguimientoId);
            };

            enlace.addEventListener('pointerenter', adelantar, { passive: true });
            enlace.addEventListener('focus', adelantar, { passive: true });
            enlace.addEventListener('touchstart', adelantar, { passive: true, once: true });
            enlace.addEventListener('mousedown', adelantar, { passive: true });

            enlace.addEventListener('click', function () {
                enlace.classList.add('is-opening-followup');
            });
        });

        const ids = [];
        const vistos = new Set();

        enlaces.forEach(function (enlace) {
            const seguimientoId = Number(enlace.dataset.dashboardSeguimientoId || 0);
            if (seguimientoId <= 0 || vistos.has(seguimientoId) || ids.length >= 4) {
                return;
            }
            vistos.add(seguimientoId);
            ids.push(seguimientoId);
        });

        window.setTimeout(function () {
            let indice = 0;
            const trabajadores = Math.min(2, ids.length);

            const siguiente = async function () {
                const posicion = indice++;
                if (posicion >= ids.length) {
                    return;
                }

                await precargarSeguimiento(ids[posicion]);
                await siguiente();
            };

            for (let trabajador = 0; trabajador < trabajadores; trabajador += 1) {
                void siguiente();
            }
        }, 220);
    });
})();
