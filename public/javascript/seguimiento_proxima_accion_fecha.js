(function () {
    'use strict';

    const obtenerCachePanel = function () {
        return window.IMPE_SEGUIMIENTO_PANEL_CACHE || null;
    };

    const obtenerSeguimiento = function (seguimientoId) {
        seguimientoId = Number(seguimientoId || 0);
        if (seguimientoId <= 0) {
            return null;
        }

        try {
            return obtenerCachePanel()?.obtener?.(seguimientoId)?.seguimiento || null;
        } catch (error) {
            return null;
        }
    };

    const fechaDesdeSql = function (valor) {
        const texto = String(valor || '').trim();
        if (texto === '') {
            return null;
        }

        const fecha = new Date(texto.replace(' ', 'T'));
        return Number.isNaN(fecha.getTime()) ? null : fecha;
    };

    const mismoDia = function (a, b) {
        return Boolean(
            a && b &&
            a.getFullYear() === b.getFullYear() &&
            a.getMonth() === b.getMonth() &&
            a.getDate() === b.getDate()
        );
    };

    const horaDe = function (fecha) {
        if (!fecha) {
            return '';
        }

        return String(fecha.getHours()).padStart(2, '0') + ':' +
            String(fecha.getMinutes()).padStart(2, '0');
    };

    const descripcionFecha = function (seguimiento, modoCorto) {
        const raw = String(seguimiento?.proxima_accion_at || '').trim();
        const label = String(seguimiento?.proxima_accion_fecha_label || '').trim();

        if (raw === '') {
            return { texto: '', vencida: false };
        }

        const fecha = fechaDesdeSql(raw);
        const ahora = new Date();
        const manana = new Date(ahora);
        manana.setDate(manana.getDate() + 1);

        if (!fecha) {
            return {
                texto: label !== '' ? label : raw,
                vencida: false
            };
        }

        if (fecha.getTime() < ahora.getTime()) {
            return {
                texto: (modoCorto ? '' : 'Vencida · ') + (label || raw),
                vencida: true
            };
        }

        if (mismoDia(fecha, ahora)) {
            return {
                texto: 'Hoy · ' + horaDe(fecha),
                vencida: false
            };
        }

        if (mismoDia(fecha, manana)) {
            return {
                texto: 'Mañana · ' + horaDe(fecha),
                vencida: false
            };
        }

        return {
            texto: (modoCorto ? '' : 'Programada · ') + (label || raw),
            vencida: false
        };
    };

    const asegurarFechaPanel = function (offcanvas) {
        const seccion = offcanvas?.querySelector('[data-work-next-section]');
        const accion = seccion?.querySelector('[data-work-next-action]');

        if (!seccion || !accion) {
            return null;
        }

        let fecha = seccion.querySelector('[data-work-next-schedule]');
        if (fecha) {
            return fecha;
        }

        fecha = document.createElement('span');
        fecha.className = 'linkage-work-next-schedule d-none';
        fecha.setAttribute('data-work-next-schedule', '');
        fecha.innerHTML = '<i class="bi bi-calendar3"></i><span></span>';
        accion.insertAdjacentElement('afterend', fecha);
        return fecha;
    };

    const pintarFechaPanel = function (offcanvas) {
        const destino = asegurarFechaPanel(offcanvas);
        if (!destino) {
            return;
        }

        const seguimientoId = Number(offcanvas.dataset.flowSeguimientoId || 0);
        const seguimiento = obtenerSeguimiento(seguimientoId);
        const descripcion = descripcionFecha(seguimiento, false);
        const texto = destino.querySelector('span');

        if (!seguimiento || descripcion.texto === '') {
            destino.classList.add('d-none');
            destino.classList.remove('is-overdue');
            if (texto && texto.textContent !== '') {
                texto.textContent = '';
            }
            return;
        }

        destino.classList.remove('d-none');
        destino.classList.toggle('is-overdue', descripcion.vencida);
        if (texto && texto.textContent !== descripcion.texto) {
            texto.textContent = descripcion.texto;
        }
    };

    const pintarFechasFilas = function () {
        document.querySelectorAll('[data-linkage-follow-row]').forEach(function (fila) {
            const seguimientoId = Number(
                fila.querySelector('[data-work-follow-id]')?.getAttribute('data-work-follow-id') || 0
            );
            const celda = fila.querySelector('[data-row-next-action]');
            if (seguimientoId <= 0 || !celda) {
                return;
            }

            const seguimiento = obtenerSeguimiento(seguimientoId);
            const descripcion = descripcionFecha(seguimiento, true);

            if (!seguimiento || descripcion.texto === '') {
                delete celda.dataset.nextSchedule;
                delete celda.dataset.nextOverdue;
                return;
            }

            if (celda.dataset.nextSchedule !== descripcion.texto) {
                celda.dataset.nextSchedule = descripcion.texto;
            }
            const vencida = descripcion.vencida ? '1' : '0';
            if (celda.dataset.nextOverdue !== vencida) {
                celda.dataset.nextOverdue = vencida;
            }
        });
    };

    document.addEventListener('DOMContentLoaded', function () {
        const offcanvas = document.getElementById('offcanvasSeguimientoTrabajo');

        if (offcanvas) {
            asegurarFechaPanel(offcanvas);

            let programado = false;
            const programarPanel = function () {
                if (programado) {
                    return;
                }
                programado = true;
                window.setTimeout(function () {
                    programado = false;
                    pintarFechaPanel(offcanvas);
                }, 0);
            };

            const observador = new MutationObserver(programarPanel);
            observador.observe(offcanvas, {
                attributes: true,
                attributeFilter: ['data-flow-seguimiento-id'],
                childList: true,
                subtree: true,
                characterData: true
            });

            offcanvas.addEventListener('shown.bs.offcanvas', programarPanel);
            document.addEventListener('impe:flow-updated', programarPanel);

            document.addEventListener('click', function (event) {
                if (event.target.closest('[data-work-follow]')) {
                    window.setTimeout(programarPanel, 80);
                    window.setTimeout(programarPanel, 350);
                }
            });
        }

        const refrescarFilas = function () {
            pintarFechasFilas();
        };

        [250, 650, 1200, 2200, 3600].forEach(function (demora) {
            window.setTimeout(refrescarFilas, demora);
        });

        document.addEventListener('impe:flow-row-updated', function () {
            window.setTimeout(refrescarFilas, 0);
        });
        document.addEventListener('impe:flow-updated', function () {
            window.setTimeout(refrescarFilas, 0);
        });
        document.addEventListener('impe:interaction-informative-saved', function () {
            window.setTimeout(refrescarFilas, 500);
        });
    });
})();
