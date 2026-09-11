(function () {
    'use strict';

    const normalizar = function (valor) {
        return String(valor || '')
            .normalize('NFD')
            .replace(/[\u0300-\u036f]/g, '')
            .trim()
            .toLowerCase();
    };

    const obtenerEstadoFecha = function (valor) {
        const coincidencia = String(valor || '').trim().match(
            /^(\d{1,2})\s+(ene|feb|mar|abr|may|jun|jul|ago|sep|oct|nov|dic)\s+(\d{4})\s+·\s+(\d{2}):(\d{2})$/i
        );

        if (!coincidencia) {
            return { clase: 'is-scheduled', etiqueta: 'Programada' };
        }

        const meses = {
            ene: 0, feb: 1, mar: 2, abr: 3, may: 4, jun: 5,
            jul: 6, ago: 7, sep: 8, oct: 9, nov: 10, dic: 11
        };
        const fecha = new Date(
            Number(coincidencia[3]),
            meses[coincidencia[2].toLowerCase()],
            Number(coincidencia[1]),
            Number(coincidencia[4]),
            Number(coincidencia[5]),
            0,
            0
        );
        const ahora = new Date();

        if (fecha.getTime() < ahora.getTime()) {
            return { clase: 'is-overdue', etiqueta: 'Vencida' };
        }

        const mismoDia =
            fecha.getFullYear() === ahora.getFullYear() &&
            fecha.getMonth() === ahora.getMonth() &&
            fecha.getDate() === ahora.getDate();

        if (mismoDia) {
            return { clase: 'is-today', etiqueta: 'Hoy' };
        }

        return { clase: 'is-scheduled', etiqueta: 'Programada' };
    };

    document.addEventListener('DOMContentLoaded', function () {
        const parametros = new URLSearchParams(window.location.search);
        const esDetalle =
            parametros.get('controller') === 'seguimientoVinculacion' &&
            parametros.get('action') === 'detalle';

        if (!esDetalle) {
            return;
        }

        const seccionResumen = Array.from(
            document.querySelectorAll('section.dashboard-panel.linkage-detail-panel')
        ).find(function (seccion) {
            return Boolean(seccion.querySelector('.linkage-detail-header'));
        });

        if (!seccionResumen || seccionResumen.querySelector('[data-expediente-next-schedule]')) {
            return;
        }

        const filaProxima = Array.from(seccionResumen.querySelectorAll('.detail-row')).find(function (fila) {
            return normalizar(fila.querySelector('span')?.textContent) === 'proxima accion';
        });
        const valorInicial = String(filaProxima?.querySelector('strong')?.textContent || '').trim();
        const tieneFecha = /^\d{1,2}\s+[a-záéíóúñ]{3}\s+\d{4}\s+·\s+\d{2}:\d{2}$/i.test(valorInicial);

        if (!tieneFecha) {
            return;
        }

        const estado = obtenerEstadoFecha(valorInicial);
        const bloque = document.createElement('div');
        bloque.className = 'linkage-expediente-next-schedule ' + estado.clase;
        bloque.setAttribute('data-expediente-next-schedule', '');
        bloque.innerHTML =
            '<span class="linkage-expediente-next-schedule-icon" aria-hidden="true">' +
                '<i class="bi bi-calendar-event"></i>' +
            '</span>' +
            '<div class="linkage-expediente-next-schedule-copy">' +
                '<small>Próxima acción programada</small>' +
                '<strong></strong>' +
                '<span>Fecha y hora comprometidas para continuar este seguimiento.</span>' +
            '</div>' +
            '<span class="linkage-expediente-next-schedule-status"></span>';

        bloque.querySelector('.linkage-expediente-next-schedule-copy strong').textContent = valorInicial;
        bloque.querySelector('.linkage-expediente-next-schedule-status').textContent = estado.etiqueta;

        const grid = seccionResumen.querySelector('.linkage-detail-operations-grid');
        if (grid) {
            grid.insertAdjacentElement('afterend', bloque);
        } else {
            seccionResumen.appendChild(bloque);
        }
    });
})();
