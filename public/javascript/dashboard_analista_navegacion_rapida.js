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

    const enfocarPrioridad = function (tablero) {
        const panel = tablero.querySelector('.analyst-attention-panel');
        if (!panel) {
            return;
        }

        panel.scrollIntoView({
            behavior: 'smooth',
            block: 'start'
        });

        panel.classList.remove('is-dashboard-focus');
        window.requestAnimationFrame(function () {
            panel.classList.add('is-dashboard-focus');
        });

        window.setTimeout(function () {
            panel.classList.remove('is-dashboard-focus');
        }, 1400);
    };

    const activarResumenSeguimientos = function (tablero) {
        const resumen = tablero.querySelector('.analyst-welcome-status');
        if (!resumen) {
            return;
        }

        const requiereAtencion = resumen.classList.contains('is-attention');
        const flecha = resumen.querySelector('.analyst-welcome-status-arrow');

        resumen.classList.remove('is-dashboard-action', 'is-dashboard-static');
        resumen.removeAttribute('tabindex');
        resumen.removeAttribute('role');
        resumen.removeAttribute('aria-label');

        if (!requiereAtencion) {
            resumen.classList.add('is-dashboard-static');
            return;
        }

        if (flecha) {
            flecha.className = 'bi bi-chevron-down analyst-welcome-status-arrow';
        }

        resumen.classList.add('is-dashboard-action');
        resumen.tabIndex = 0;
        resumen.setAttribute('role', 'button');
        resumen.setAttribute('aria-label', 'Ir a los seguimientos que requieren atención');

        const abrir = function () {
            enfocarPrioridad(tablero);
        };

        resumen.addEventListener('click', abrir);
        resumen.addEventListener('keydown', function (evento) {
            if (evento.key === 'Enter' || evento.key === ' ') {
                evento.preventDefault();
                abrir();
            }
        });
    };

    const configurarMetricas = function (tablero) {
        /*
         * dashboard_analista_refinamientos.js convierte todas las métricas en
         * enlaces hacia Seguimiento. Aquí las reemplazamos por copias limpias
         * para que sólo Reuniones próximas conserve una navegación específica.
         */
        const tarjetasOriginales = Array.from(tablero.querySelectorAll('.analyst-metric-card'));

        tarjetasOriginales.forEach(function (tarjeta) {
            const copia = tarjeta.cloneNode(true);
            tarjeta.replaceWith(copia);
        });

        const agendaUrl = 'index.php?controller=agendaReunion&action=index';

        tablero.querySelectorAll('.analyst-metric-card').forEach(function (tarjeta) {
            const etiqueta = String(tarjeta.querySelector('.metric-label')?.textContent || '')
                .trim()
                .toLowerCase();

            tarjeta.classList.remove('is-actionable');
            tarjeta.removeAttribute('tabindex');
            tarjeta.removeAttribute('role');
            tarjeta.removeAttribute('aria-label');

            if (!etiqueta.includes('reuniones')) {
                return;
            }

            tarjeta.classList.add('is-actionable', 'is-agenda-action');
            tarjeta.tabIndex = 0;
            tarjeta.setAttribute('role', 'link');
            tarjeta.setAttribute('aria-label', 'Abrir agenda de reuniones próximas');

            const abrirAgenda = function () {
                window.location.href = agendaUrl;
            };

            tarjeta.addEventListener('click', abrirAgenda);
            tarjeta.addEventListener('keydown', function (evento) {
                if (evento.key === 'Enter' || evento.key === ' ') {
                    evento.preventDefault();
                    abrirAgenda();
                }
            });
        });
    };

    const limpiarAccesosRedundantes = function (tablero) {
        const enlaceActividad = tablero.querySelector('.analyst-activity-panel .analyst-panel-link');
        if (enlaceActividad) {
            enlaceActividad.remove();
        }
    };

    document.addEventListener('DOMContentLoaded', function () {
        const tablero = document.querySelector('[data-analyst-dashboard]');
        if (!tablero || Number(window.IMPE_CURRENT_ROLE_ID || 0) !== 4) {
            return;
        }

        activarResumenSeguimientos(tablero);
        configurarMetricas(tablero);
        limpiarAccesosRedundantes(tablero);

        const enlaces = Array.from(tablero.querySelectorAll(
            '.analyst-work-button, .analyst-upcoming-item'
        ));

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

        if (ids.length === 0) {
            return;
        }

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
