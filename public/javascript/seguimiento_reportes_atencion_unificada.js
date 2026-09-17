(function () {
    'use strict';

    function esReporteSeguimiento() {
        const parametros = new URLSearchParams(window.location.search);
        return parametros.get('controller') === 'seguimientoVinculacionReporte' &&
            parametros.get('modal') !== '1' &&
            parametros.get('generar') === '1';
    }

    function normalizarTexto(valor) {
        return String(valor || '')
            .toLowerCase()
            .normalize('NFD')
            .replace(/[\u0300-\u036f]/g, '')
            .trim();
    }

    function formatearNumero(valor) {
        const numero = Number(valor);
        if (!Number.isFinite(numero)) {
            return '0';
        }
        return new Intl.NumberFormat('es-MX', {
            maximumFractionDigits: Number.isInteger(numero) ? 0 : 1
        }).format(numero);
    }

    function construirUrl() {
        const ids = Array.isArray(window.IMPE_REPORTE_SEGUIMIENTO_IDS)
            ? window.IMPE_REPORTE_SEGUIMIENTO_IDS
                .map(function (id) { return Number(id); })
                .filter(function (id) { return Number.isInteger(id) && id > 0; })
            : [];

        if (ids.length === 0) {
            return '';
        }

        const actual = new URL(window.location.href);
        const url = new URL('index.php', window.location.href);
        url.searchParams.set('controller', 'seguimientoReporteAnalitica');
        url.searchParams.set('action', 'actividad');
        url.searchParams.set('ids', ids.join(','));

        const fechaInicial = actual.searchParams.get('fecha_inicial') || '';
        const fechaFinal = actual.searchParams.get('fecha_final') || '';
        if (fechaInicial) {
            url.searchParams.set('fecha_inicial', fechaInicial);
        }
        if (fechaFinal) {
            url.searchParams.set('fecha_final', fechaFinal);
        }

        return url.toString();
    }

    function obtenerTarjetaAtencion(root) {
        return Array.from(
            root.querySelectorAll('[data-operational-summary] .seguimiento-report-summary-card')
        ).find(function (tarjeta) {
            const etiqueta = tarjeta.querySelector('.metric-label');
            return normalizarTexto(etiqueta?.textContent || '').includes('requieren atencion');
        }) || null;
    }

    function marcarCarga(root) {
        const tarjeta = obtenerTarjetaAtencion(root);
        const valor = tarjeta?.querySelector('.metric-value');
        const detalle = tarjeta?.querySelector('.seguimiento-report-summary-detail');

        if (valor) {
            valor.textContent = '—';
        }
        if (detalle) {
            detalle.textContent = 'Calculando atención…';
        }

        const panel = root.querySelector('[data-attention-panel]');
        if (!panel) {
            return;
        }

        panel.classList.remove('seguimiento-report-attention-compact-empty');
        panel.innerHTML =
            '<div class="seguimiento-report-attention-empty">' +
                '<span><span class="spinner-border spinner-border-sm" aria-hidden="true"></span></span>' +
                '<div>' +
                    '<strong>Calculando atención operativa…</strong>' +
                    '<p>Revisando acciones, reuniones y tiempos de espera de los seguimientos incluidos.</p>' +
                '</div>' +
            '</div>';
    }

    function actualizarTarjeta(root, atencion) {
        const tarjeta = obtenerTarjetaAtencion(root);
        if (!tarjeta) {
            return;
        }

        const valor = tarjeta.querySelector('.metric-value');
        const etiqueta = tarjeta.querySelector('.metric-label');
        const detalle = tarjeta.querySelector('.seguimiento-report-summary-detail');

        if (valor) {
            valor.textContent = formatearNumero(atencion.total || 0);
        }
        if (etiqueta) {
            etiqueta.textContent = 'Requieren atención';
        }
        if (detalle) {
            detalle.textContent = formatearNumero(atencion.porcentaje || 0) + '% del total';
        }
    }

    function crearEncabezado() {
        const encabezado = document.createElement('div');
        encabezado.className = 'seguimiento-report-section-heading';
        encabezado.innerHTML =
            '<div>' +
                '<span class="report-eyebrow">ATENCIÓN REQUERIDA</span>' +
                '<h3 class="panel-title mb-1">Seguimientos que requieren una acción</h3>' +
                '<p class="page-subtitle mb-0">Mismo criterio operativo de Inicio: acciones, reuniones y esperas que necesitan revisión.</p>' +
            '</div>';
        return encabezado;
    }

    function formatearFecha(valor) {
        const texto = String(valor || '').trim();
        if (texto === '') {
            return '';
        }

        const fecha = new Date(texto.replace(' ', 'T'));
        if (Number.isNaN(fecha.getTime())) {
            return '';
        }

        return new Intl.DateTimeFormat('es-MX', {
            day: '2-digit',
            month: 'short',
            hour: '2-digit',
            minute: '2-digit'
        }).format(fecha);
    }

    function renderizarPanel(root, atencion) {
        const panel = root.querySelector('[data-attention-panel]');
        if (!panel) {
            return;
        }

        const casos = Array.isArray(atencion.casos) ? atencion.casos : [];
        panel.classList.remove('seguimiento-report-attention-compact-empty');
        panel.replaceChildren();

        if (casos.length === 0) {
            panel.classList.add('seguimiento-report-attention-compact-empty');
            const vacio = document.createElement('div');
            vacio.className = 'seguimiento-report-attention-empty';
            vacio.innerHTML =
                '<span><i class="bi bi-check2-circle"></i></span>' +
                '<div>' +
                    '<strong>Sin pendientes operativos</strong>' +
                    '<p>No hay acciones o reuniones vencidas, confirmaciones próximas ni esperas que superen los umbrales de atención.</p>' +
                '</div>';
            panel.appendChild(vacio);
            return;
        }

        panel.appendChild(crearEncabezado());

        const lista = document.createElement('div');
        lista.className = 'seguimiento-report-attention-list';

        casos.forEach(function (caso) {
            const fila = document.createElement('div');
            fila.className = 'seguimiento-report-attention-row';

            const principal = document.createElement('div');
            principal.className = 'seguimiento-report-attention-main';

            const nombre = document.createElement('strong');
            nombre.textContent = String(caso.nombre_entidad || 'Institución');

            const motivo = document.createElement('span');
            motivo.textContent = String(caso.motivo || 'Requiere revisión');

            principal.appendChild(nombre);
            principal.appendChild(motivo);

            const meta = document.createElement('div');
            meta.className = 'seguimiento-report-attention-action';

            const ubicacion = [caso.municipio, caso.estado_nombre]
                .map(function (valor) { return String(valor || '').trim(); })
                .filter(Boolean)
                .join(' · ');
            const fecha = formatearFecha(caso.fecha_referencia);

            const tituloMeta = document.createElement('strong');
            tituloMeta.textContent = ubicacion || 'Seguimiento incluido';
            const detalleMeta = document.createElement('small');
            detalleMeta.textContent = fecha !== ''
                ? 'Referencia: ' + fecha
                : 'Prioridad operativa: ' + formatearNumero(caso.prioridad || 0);

            meta.appendChild(tituloMeta);
            meta.appendChild(detalleMeta);

            fila.appendChild(principal);
            fila.appendChild(meta);
            lista.appendChild(fila);
        });

        panel.appendChild(lista);
    }

    function aplicar() {
        if (!esReporteSeguimiento()) {
            return;
        }

        const root = document.querySelector('section[aria-labelledby="titulo-reporte-seguimiento"]');
        if (!root) {
            return;
        }

        marcarCarga(root);
        const url = construirUrl();
        if (url === '') {
            actualizarTarjeta(root, { total: 0, porcentaje: 0 });
            renderizarPanel(root, { casos: [] });
            return;
        }

        fetch(url, {
            headers: { 'X-Requested-With': 'fetch' },
            cache: 'no-store'
        })
            .then(function (respuesta) {
                if (!respuesta.ok) {
                    throw new Error('No fue posible consultar la atención operativa.');
                }
                return respuesta.json();
            })
            .then(function (datos) {
                const atencion = datos && datos.ok && datos.resumen
                    ? datos.resumen.atencion
                    : null;

                if (!atencion) {
                    throw new Error('La respuesta no contiene atención operativa.');
                }

                actualizarTarjeta(root, atencion);
                renderizarPanel(root, atencion);
            })
            .catch(function () {
                const tarjeta = obtenerTarjetaAtencion(root);
                const valor = tarjeta?.querySelector('.metric-value');
                const detalle = tarjeta?.querySelector('.seguimiento-report-summary-detail');
                if (valor) {
                    valor.textContent = '—';
                }
                if (detalle) {
                    detalle.textContent = 'No disponible';
                }

                const panel = root.querySelector('[data-attention-panel]');
                if (panel) {
                    panel.classList.remove('seguimiento-report-attention-compact-empty');
                    panel.innerHTML =
                        '<div class="seguimiento-report-attention-empty">' +
                            '<span><i class="bi bi-exclamation-circle"></i></span>' +
                            '<div>' +
                                '<strong>No fue posible calcular la atención operativa</strong>' +
                                '<p>El resto del reporte sigue disponible; vuelve a cargar para intentar nuevamente.</p>' +
                            '</div>' +
                        '</div>';
                }
            });
    }

    function iniciar() {
        window.setTimeout(aplicar, 5);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', iniciar, { once: true });
    } else {
        iniciar();
    }
})();
