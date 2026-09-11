(function () {
    'use strict';

    const obtenerSeguimientoDesdeHref = function (href) {
        try {
            const url = new URL(String(href || ''), window.location.href);
            return Number(
                url.searchParams.get('abrir_seguimiento') ||
                url.searchParams.get('trabajar_id') ||
                url.searchParams.get('id') ||
                0
            );
        } catch (error) {
            return 0;
        }
    };

    const estadoReunion = function (seguimientoId) {
        const mapa = window.IMPE_ANALISTA_REUNIONES || {};
        return mapa[String(seguimientoId)] || null;
    };

    const fechaVisible = function (texto) {
        const partes = String(texto || '').split('·');
        if (partes.length <= 1) {
            return String(texto || '').trim();
        }
        return partes.slice(1).join('·').trim();
    };

    const decorarProximos = function (tablero) {
        tablero.querySelectorAll('.analyst-upcoming-item').forEach(function (item) {
            const icono = item.querySelector('.analyst-upcoming-icon');
            if (!icono || !icono.classList.contains('is-meeting')) {
                return;
            }

            const seguimientoId = obtenerSeguimientoDesdeHref(item.getAttribute('href'));
            const reunion = estadoReunion(seguimientoId);
            if (!reunion) {
                return;
            }

            const estado = String(reunion.estado || '').toUpperCase();
            const copia = item.querySelector('div');
            const detalle = copia?.querySelector('span');
            if (!copia || !detalle) {
                return;
            }

            copia.classList.add('analyst-upcoming-copy');
            const fecha = fechaVisible(detalle.textContent);
            let titulo = 'Reunión';
            let etiqueta = '';
            let clase = '';
            let iconoEstado = '';

            if (estado === 'SOLICITADA') {
                titulo = 'Reunión propuesta';
                etiqueta = 'Pendiente de confirmación';
                clase = 'is-pending';
                iconoEstado = 'bi-clock-history';
                item.classList.add('is-meeting-pending');
            } else if (estado === 'CONFIRMADA') {
                titulo = 'Reunión confirmada';
                etiqueta = 'Confirmada por Cuenta Clave';
                clase = 'is-confirmed';
                iconoEstado = 'bi-check2-circle';
                item.classList.add('is-meeting-confirmed');
            } else if (estado === 'CORREO_ENVIADO') {
                titulo = 'Reunión agendada';
                etiqueta = 'Confirmada';
                clase = 'is-confirmed';
                iconoEstado = 'bi-check2-circle';
                item.classList.add('is-meeting-confirmed');
            }

            detalle.textContent = titulo + (fecha !== '' ? ' · ' + fecha : '');

            if (etiqueta !== '' && !copia.querySelector('.analyst-upcoming-meeting-status')) {
                const badge = document.createElement('span');
                badge.className = 'analyst-upcoming-meeting-status ' + clase;
                badge.innerHTML = '<i class="bi ' + iconoEstado + '"></i><span></span>';
                badge.querySelector('span').textContent = etiqueta;
                copia.appendChild(badge);
            }
        });
    };

    document.addEventListener('DOMContentLoaded', function () {
        const tablero = document.querySelector('[data-analyst-dashboard]');
        if (!tablero || Number(window.IMPE_CURRENT_ROLE_ID || 0) !== 4) {
            return;
        }
        decorarProximos(tablero);
    });
})();
