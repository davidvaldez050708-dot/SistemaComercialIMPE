(function () {
    'use strict';

    const ETAPAS = [
        'Investigación y contacto',
        'Validación de datos',
        'Oficio y envío',
        'Envío y respuesta',
        'Respuesta y coordinación',
        'Reunión',
        'Convenio / cierre',
        'Descartado',
        'Sin ruta disponible'
    ];

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

    function numero(valor) {
        const n = Number(valor);
        return Number.isFinite(n) ? n : 0;
    }

    function formatearNumero(valor) {
        return new Intl.NumberFormat('es-MX', {
            maximumFractionDigits: Number.isInteger(Number(valor)) ? 0 : 1
        }).format(numero(valor));
    }

    function indiceColumna(tabla, nombre) {
        const buscado = normalizarTexto(nombre);
        return Array.from(tabla.querySelectorAll('thead th')).findIndex(function (th) {
            return normalizarTexto(th.textContent) === buscado;
        });
    }

    function crearContenedor(root) {
        let contenedor = root.querySelector('[data-report-advanced-insights]');
        if (contenedor) {
            return contenedor;
        }

        contenedor = document.createElement('div');
        contenedor.className = 'row g-4 mb-4 seguimiento-report-advanced-insights';
        contenedor.setAttribute('data-report-advanced-insights', '');

        const referencia = root.querySelector('[data-attention-panel]') ||
            root.querySelector('[data-operational-summary]');

        if (referencia) {
            referencia.insertAdjacentElement('afterend', contenedor);
        } else {
            root.prepend(contenedor);
        }

        return contenedor;
    }

    function crearColumnaPanel(contenedor, atributo) {
        let columna = contenedor.querySelector('[' + atributo + ']');
        if (columna) {
            return columna;
        }

        columna = document.createElement('div');
        columna.className = 'col-12 col-xl-6';
        columna.setAttribute(atributo, '');
        contenedor.appendChild(columna);
        return columna;
    }

    function panelBase(titulo, subtitulo, icono) {
        const panel = document.createElement('section');
        panel.className = 'dashboard-panel seguimiento-report-insight-panel h-100';

        const heading = document.createElement('div');
        heading.className = 'seguimiento-report-insight-heading';
        heading.innerHTML =
            '<div>' +
                '<h3 class="panel-title mb-1">' + titulo + '</h3>' +
                '<p class="page-subtitle mb-0">' + subtitulo + '</p>' +
            '</div>' +
            '<span class="seguimiento-report-insight-icon" aria-hidden="true">' +
                '<i class="bi ' + icono + '"></i>' +
            '</span>';
        panel.appendChild(heading);
        return panel;
    }

    function crearMetrica(valor, etiqueta, detalle) {
        const item = document.createElement('article');
        item.className = 'seguimiento-report-insight-metric';
        item.innerHTML =
            '<strong>' + String(valor) + '</strong>' +
            '<span>' + etiqueta + '</span>' +
            (detalle ? '<small>' + detalle + '</small>' : '');
        return item;
    }

    function crearFilaBarra(etiqueta, total, porcentaje) {
        const fila = document.createElement('div');
        fila.className = 'seguimiento-report-breakdown-row';
        const ancho = Math.max(0, Math.min(100, numero(porcentaje)));
        fila.innerHTML =
            '<div class="seguimiento-report-breakdown-copy">' +
                '<span>' + etiqueta + '</span>' +
                '<strong>' + formatearNumero(total) + ' · ' + ancho.toFixed(1) + '%</strong>' +
            '</div>' +
            '<div class="seguimiento-report-breakdown-track" aria-hidden="true">' +
                '<span style="width:' + ancho.toFixed(2) + '%"></span>' +
            '</div>';
        return fila;
    }

    function clasificarEtapa(accion, estatus) {
        const a = normalizarTexto(accion);
        const e = normalizarTexto(estatus);

        if (e.includes('descartado')) {
            return 'Descartado';
        }
        if (a.includes('convenio')) {
            return 'Convenio / cierre';
        }
        if (a.includes('reunion') || a.includes('agendar')) {
            return 'Reunión';
        }
        if (
            a.includes('seguimiento por correo') ||
            a.includes('coordinar por correo') ||
            a.includes('continuar a reunion')
        ) {
            return 'Respuesta y coordinación';
        }
        if (a.includes('respuesta')) {
            return 'Envío y respuesta';
        }
        if (a.includes('oficio') || a.includes('pdf')) {
            return 'Oficio y envío';
        }
        if (
            a.includes('verificar') ||
            a.includes('datos verificados') ||
            a.includes('completar informacion')
        ) {
            return 'Validación de datos';
        }
        if (
            a.includes('llamada') ||
            a.includes('contacto') ||
            a.includes('investigacion') ||
            a.includes('iniciar')
        ) {
            return 'Investigación y contacto';
        }

        if (e.includes('oficio')) {
            return 'Oficio y envío';
        }
        if (e.includes('esperando respuesta')) {
            return 'Envío y respuesta';
        }
        if (e.includes('datos verificados')) {
            return 'Validación de datos';
        }
        if (e.includes('contactando') || e.includes('nuevo') || e.includes('no localizado')) {
            return 'Investigación y contacto';
        }

        return 'Sin ruta disponible';
    }

    function renderizarAvance(root, columna) {
        const tabla = root.querySelector('.seguimiento-report-detail-panel table');
        const panel = panelBase(
            'Avance de vinculación',
            'Punto operativo actual de los seguimientos incluidos.',
            'bi-signpost-split'
        );
        columna.replaceChildren(panel);

        if (!tabla) {
            panel.insertAdjacentHTML(
                'beforeend',
                '<p class="seguimiento-report-insight-empty mb-0">No hay seguimientos para calcular el avance.</p>'
            );
            return;
        }

        const indiceAccion = indiceColumna(tabla, 'Próxima acción');
        const indiceEstatus = indiceColumna(tabla, 'Estatus');
        const filas = Array.from(tabla.querySelectorAll('tbody tr'));
        const conteos = {};

        ETAPAS.forEach(function (etapa) {
            conteos[etapa] = 0;
        });

        filas.forEach(function (fila) {
            const accion = indiceAccion >= 0
                ? fila.children[indiceAccion]?.textContent.trim() || ''
                : '';
            const estatus = indiceEstatus >= 0
                ? fila.children[indiceEstatus]?.textContent.trim() || ''
                : '';
            const etapa = clasificarEtapa(accion, estatus);
            conteos[etapa] = (conteos[etapa] || 0) + 1;
        });

        const total = filas.length;
        const lista = document.createElement('div');
        lista.className = 'seguimiento-report-breakdown-list';

        ETAPAS.forEach(function (etapa) {
            const cantidad = conteos[etapa] || 0;
            if (cantidad <= 0) {
                return;
            }
            const porcentaje = total > 0 ? (cantidad / total) * 100 : 0;
            lista.appendChild(crearFilaBarra(etapa, cantidad, porcentaje));
        });

        panel.appendChild(lista);
    }

    function esperarAcciones(root, columna, intento) {
        const pendientes = root.querySelectorAll('.seguimiento-report-next-action-cell.is-loading').length;
        if (pendientes > 0 && intento < 40) {
            window.setTimeout(function () {
                esperarAcciones(root, columna, intento + 1);
            }, 150);
            return;
        }

        renderizarAvance(root, columna);
    }

    function construirUrlAnalitica(ids) {
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

    function renderizarActividad(columna, resumen) {
        const periodo = resumen.periodo || {};
        const tienePeriodo = Boolean(periodo.fecha_inicial || periodo.fecha_final);
        const panel = panelBase(
            'Actividad y contacto',
            tienePeriodo
                ? 'Interacciones de contacto dentro del periodo seleccionado. Los eventos automáticos del sistema no se cuentan.'
                : 'Interacciones de contacto del historial disponible. Los eventos automáticos del sistema no se cuentan.',
            'bi-telephone-forward'
        );
        columna.replaceChildren(panel);

        const metricas = document.createElement('div');
        metricas.className = 'seguimiento-report-insight-metrics';
        metricas.appendChild(crearMetrica(
            formatearNumero(resumen.interacciones),
            'Interacciones de contacto',
            'Sin eventos automáticos'
        ));
        metricas.appendChild(crearMetrica(
            formatearNumero(resumen.cobertura_actividad) + '%',
            'Cobertura de actividad',
            formatearNumero(resumen.seguimientos_con_actividad) + ' de ' +
                formatearNumero(resumen.seguimientos_considerados) + ' seguimientos'
        ));
        metricas.appendChild(crearMetrica(
            formatearNumero(resumen.llamadas?.total || 0),
            'Llamadas',
            'Registradas como interacción'
        ));
        metricas.appendChild(crearMetrica(
            formatearNumero(resumen.promedio_por_seguimiento),
            'Por seguimiento',
            'Promedio de interacciones'
        ));
        panel.appendChild(metricas);

        const canales = resumen.canales || {};
        const total = Math.max(1, numero(resumen.interacciones));
        const bloqueCanales = document.createElement('div');
        bloqueCanales.className = 'seguimiento-report-channel-block';
        bloqueCanales.innerHTML = '<strong class="seguimiento-report-insight-label">Canales utilizados</strong>';
        const listaCanales = document.createElement('div');
        listaCanales.className = 'seguimiento-report-channel-grid';

        [
            ['Llamadas', numero(canales.llamadas)],
            ['Correos', numero(canales.correos)],
            ['WhatsApp', numero(canales.whatsapp)],
            ['Otros', numero(canales.otros)]
        ].forEach(function (item) {
            const caja = document.createElement('div');
            caja.innerHTML = '<span>' + item[0] + '</span><strong>' + formatearNumero(item[1]) + '</strong>';
            caja.title = ((item[1] / total) * 100).toFixed(1) + '% de las interacciones de contacto';
            listaCanales.appendChild(caja);
        });
        bloqueCanales.appendChild(listaCanales);
        panel.appendChild(bloqueCanales);

        const llamadas = resumen.llamadas || {};
        if (numero(llamadas.total) > 0) {
            const resultados = document.createElement('div');
            resultados.className = 'seguimiento-report-call-results';
            resultados.innerHTML =
                '<div class="seguimiento-report-call-heading">' +
                    '<strong>Resultado de llamadas</strong>' +
                    '<span>' + formatearNumero(llamadas.tasa_contacto) + '% registradas como contacto efectivo</span>' +
                '</div>' +
                '<div class="seguimiento-report-call-chips">' +
                    '<span><b>' + formatearNumero(llamadas.contactadas) + '</b> Contactadas</span>' +
                    '<span><b>' + formatearNumero(llamadas.sin_respuesta) + '</b> Sin respuesta</span>' +
                    '<span><b>' + formatearNumero(llamadas.numero_incorrecto) + '</b> Número incorrecto</span>' +
                    '<span><b>' + formatearNumero(llamadas.volver_llamar) + '</b> Volver a llamar</span>' +
                '</div>';
            panel.appendChild(resultados);
        }
    }

    function cargarActividad(columna) {
        const ids = Array.isArray(window.IMPE_REPORTE_SEGUIMIENTO_IDS)
            ? window.IMPE_REPORTE_SEGUIMIENTO_IDS
                .map(function (id) { return Number(id); })
                .filter(function (id) { return Number.isInteger(id) && id > 0; })
            : [];

        const panelCarga = panelBase(
            'Actividad y contacto',
            'Calculando las interacciones de los seguimientos incluidos…',
            'bi-telephone-forward'
        );
        panelCarga.insertAdjacentHTML(
            'beforeend',
            '<div class="seguimiento-report-insight-loading"><span class="spinner-border spinner-border-sm" aria-hidden="true"></span> Consultando actividad…</div>'
        );
        columna.replaceChildren(panelCarga);

        if (ids.length === 0) {
            panelCarga.querySelector('.seguimiento-report-insight-loading').textContent =
                'No hay seguimientos para calcular la actividad.';
            return;
        }

        fetch(construirUrlAnalitica(ids), {
            headers: { 'X-Requested-With': 'fetch' },
            cache: 'no-store'
        })
            .then(function (respuesta) {
                if (!respuesta.ok) {
                    throw new Error('No fue posible consultar la analítica.');
                }
                return respuesta.json();
            })
            .then(function (datos) {
                if (!datos || !datos.ok || !datos.resumen) {
                    throw new Error('La respuesta de analítica no es válida.');
                }
                renderizarActividad(columna, datos.resumen);
            })
            .catch(function () {
                panelCarga.querySelector('.seguimiento-report-insight-loading').textContent =
                    'No fue posible calcular la actividad en este momento.';
            });
    }

    function diferenciarEstatusExistente(root) {
        const titulo = root.querySelector('#grafica-estatus-titulo');
        if (titulo) {
            titulo.textContent = 'Estatus registrado';
        }
        const panel = titulo?.closest('section');
        const subtitulo = panel?.querySelector('.seguimiento-report-panel-subtitle');
        if (subtitulo) {
            subtitulo.textContent = 'Clasificación administrativa actual de los seguimientos incluidos.';
        }
    }

    function aplicar() {
        if (!esReporteSeguimiento()) {
            return;
        }

        const root = document.querySelector('section[aria-labelledby="titulo-reporte-seguimiento"]');
        if (!root || root.hasAttribute('data-report-analytics-ready')) {
            return;
        }

        root.setAttribute('data-report-analytics-ready', '');
        diferenciarEstatusExistente(root);

        const contenedor = crearContenedor(root);
        const columnaAvance = crearColumnaPanel(contenedor, 'data-report-flow-insight');
        const columnaActividad = crearColumnaPanel(contenedor, 'data-report-activity-insight');

        const panelAvanceCarga = panelBase(
            'Avance de vinculación',
            'Leyendo el siguiente paso operativo de cada seguimiento…',
            'bi-signpost-split'
        );
        panelAvanceCarga.insertAdjacentHTML(
            'beforeend',
            '<div class="seguimiento-report-insight-loading"><span class="spinner-border spinner-border-sm" aria-hidden="true"></span> Calculando avance…</div>'
        );
        columnaAvance.replaceChildren(panelAvanceCarga);

        esperarAcciones(root, columnaAvance, 0);
        cargarActividad(columnaActividad);
    }

    function iniciar() {
        window.setTimeout(aplicar, 20);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', iniciar, { once: true });
    } else {
        iniciar();
    }
})();
