(function () {
    'use strict';

    function esReporteSeguimiento() {
        const parametros = new URLSearchParams(window.location.search);
        return parametros.get('controller') === 'seguimientoVinculacionReporte';
    }

    function crearAyudaCompacta(formulario) {
        const textosAyuda = Array.from(formulario.querySelectorAll('.form-text'));

        if (textosAyuda.length === 0 || formulario.querySelector('[data-report-help-compact]')) {
            return;
        }

        textosAyuda.forEach(function (nodo) {
            nodo.classList.add('d-none');
        });

        const ayuda = document.createElement('div');
        ayuda.className = 'seguimiento-report-help';
        ayuda.setAttribute('data-report-help-compact', '');

        const icono = document.createElement('i');
        icono.className = 'bi bi-info-circle';
        icono.setAttribute('aria-hidden', 'true');

        const texto = document.createElement('span');
        texto.textContent = 'El periodo se aplica sobre la fecha de inicio registrada en cada seguimiento. El tipo de actividad utiliza el canal de la última interacción registrada.';

        ayuda.appendChild(icono);
        ayuda.appendChild(texto);

        const acciones = formulario.querySelector('[data-report-actions-refined]');
        if (acciones) {
            formulario.insertBefore(ayuda, acciones);
        } else {
            formulario.appendChild(ayuda);
        }
    }

    function refinarBotones(formulario) {
        const limpiar = Array.from(formulario.querySelectorAll('a.btn')).find(function (enlace) {
            return enlace.textContent.trim().toLowerCase().includes('limpiar filtros');
        });
        const generar = formulario.querySelector('button[type="submit"]');

        if (limpiar) {
            limpiar.classList.remove('btn-secondary');
            limpiar.classList.add('btn-system-cancel', 'seguimiento-report-action');
            limpiar.querySelector('i')?.classList.remove('me-2');
        }

        if (generar) {
            generar.classList.remove('btn-system-primary');
            generar.classList.add('btn-system-save', 'seguimiento-report-action', 'seguimiento-report-generate');
            generar.querySelector('i')?.classList.remove('me-2');
        }

        const acciones = limpiar?.parentElement || generar?.parentElement;
        if (acciones) {
            acciones.classList.add('seguimiento-report-actions');
            acciones.setAttribute('data-report-actions-refined', '');
        }
    }

    function refinarEncabezado(panel) {
        const encabezado = panel.querySelector(':scope > .d-flex.align-items-start');
        if (!encabezado) {
            return;
        }

        encabezado.classList.add('seguimiento-report-filter-heading');

        const copia = encabezado.querySelector('div');
        const titulo = copia?.querySelector('.panel-title');

        if (copia && titulo && !copia.querySelector('.report-eyebrow')) {
            const etiqueta = document.createElement('span');
            etiqueta.className = 'report-eyebrow';
            etiqueta.textContent = 'SEGUIMIENTO DE VINCULACIÓN';
            copia.insertBefore(etiqueta, titulo);
        }
    }

    function refinarRegreso(formulario) {
        const parametros = new URLSearchParams(window.location.search);
        const origen = parametros.get('origen');
        const volver = document.querySelector('.linkage-back-link');

        if (origen !== 'reportes') {
            return;
        }

        if (volver) {
            volver.href = window.location.pathname + '?controller=reporte&action=index';
            volver.innerHTML = '<i class="bi bi-arrow-left"></i> Volver a Reportes';
        }

        if (!formulario.querySelector('input[name="origen"]')) {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'origen';
            input.value = 'reportes';
            formulario.appendChild(input);
        }

        const limpiar = Array.from(formulario.querySelectorAll('a')).find(function (enlace) {
            return enlace.textContent.trim().toLowerCase().includes('limpiar filtros');
        });

        if (limpiar) {
            const url = new URL(limpiar.href, window.location.origin);
            url.searchParams.set('origen', 'reportes');
            limpiar.href = url.toString();
        }
    }

    function refinarTopbar() {
        const titulo = document.querySelector('.admin-topbar .page-title');
        const subtitulo = document.querySelector('.admin-topbar .page-subtitle');

        if (titulo) {
            titulo.textContent = 'Reportes';
        }

        if (subtitulo) {
            subtitulo.textContent = 'Seguimiento de vinculación';
        }
    }

    function refinarExportacion() {
        document.querySelectorAll('a[href*="action=exportarPdf"]').forEach(function (enlace) {
            enlace.classList.add('seguimiento-report-export');
            enlace.querySelector('i')?.classList.remove('me-2');
        });
    }

    function refinarColumnasModal(formulario) {
        const fila = formulario.querySelector('.modal-body > .row');
        if (!fila) {
            return;
        }

        fila.classList.remove('g-3');
        fila.classList.add('gx-3', 'gy-2', 'seguimiento-report-modal-grid');

        Array.from(fila.children).forEach(function (columna) {
            if (!(columna instanceof HTMLElement)) {
                return;
            }

            columna.classList.remove('col-xl-3', 'col-xl-4');
            columna.classList.add('col-12', 'col-md-6', 'col-lg-4');
        });
    }

    function numeroDesdeTexto(valor) {
        const coincidencia = String(valor || '').replace(/,/g, '').match(/-?\d+(?:\.\d+)?/);
        return coincidencia ? Number(coincidencia[0]) : 0;
    }

    function obtenerMapaFiltros(seccion) {
        const filtros = {};
        if (!seccion) {
            return filtros;
        }

        seccion.querySelectorAll('.row > div').forEach(function (columna) {
            const etiqueta = columna.querySelector('span');
            const valor = columna.querySelector('strong');
            if (!etiqueta || !valor) {
                return;
            }
            filtros[etiqueta.textContent.trim()] = valor.textContent.trim();
        });

        return filtros;
    }

    function crearContextoReporte(root, filtros) {
        const titulo = root.querySelector('#titulo-reporte-seguimiento');
        const copia = titulo?.parentElement;
        if (!copia || copia.querySelector('[data-report-context]')) {
            return;
        }

        const partes = [];
        const estado = filtros.Estado || 'Todos';
        const municipio = filtros.Municipio || 'Todos';
        const responsable = filtros.Responsable || 'Todos';
        const periodo = filtros.Periodo || 'Todos';

        if (estado !== 'Todos') {
            partes.push(estado);
        }
        if (municipio !== 'Todos') {
            partes.push(municipio);
        } else if (estado !== 'Todos') {
            partes.push('Todos los municipios');
        }
        if (responsable !== 'Todos') {
            partes.push(responsable);
        }
        if (periodo !== 'Todos') {
            partes.push(periodo);
        }

        const contexto = document.createElement('div');
        contexto.className = 'seguimiento-report-context';
        contexto.setAttribute('data-report-context', '');
        contexto.textContent = partes.length > 0 ? partes.join(' · ') : 'Todos los seguimientos disponibles';
        copia.appendChild(contexto);
    }

    function agregarBotonEditarFiltros(root, formulario) {
        const cabecera = root.querySelector(':scope > .d-flex');
        const acciones = cabecera?.querySelector('.d-flex.align-items-center');
        if (!acciones || acciones.querySelector('[data-edit-report-filters]')) {
            return;
        }

        const boton = document.createElement('button');
        boton.type = 'button';
        boton.className = 'btn btn-system-light seguimiento-report-edit-filters';
        boton.setAttribute('data-edit-report-filters', '');
        boton.innerHTML = '<i class="bi bi-sliders"></i><span>Editar filtros</span>';
        boton.addEventListener('click', function () {
            formulario.scrollIntoView({ behavior: 'smooth', block: 'start' });
        });

        const exportar = acciones.querySelector('a[href*="action=exportarPdf"]');
        if (exportar) {
            acciones.insertBefore(boton, exportar);
        } else {
            acciones.appendChild(boton);
        }
    }

    function buscarMetricaPorEtiqueta(root, textoBuscado) {
        const buscado = textoBuscado.toLowerCase();
        return Array.from(root.querySelectorAll('.metric-card')).find(function (tarjeta) {
            const etiqueta = tarjeta.querySelector('.metric-label');
            return etiqueta && etiqueta.textContent.trim().toLowerCase().includes(buscado);
        }) || null;
    }

    function valorMetrica(tarjeta) {
        return numeroDesdeTexto(tarjeta?.querySelector('.metric-value')?.textContent || '0');
    }

    function crearTarjetaResumen(icono, valor, etiqueta, detalle) {
        const tarjeta = document.createElement('article');
        tarjeta.className = 'metric-card seguimiento-report-summary-card';

        const iconoWrap = document.createElement('span');
        iconoWrap.className = 'metric-icon';
        iconoWrap.innerHTML = '<i class="bi ' + icono + '"></i>';

        const copia = document.createElement('div');
        const valorNodo = document.createElement('p');
        valorNodo.className = 'metric-value';
        valorNodo.textContent = String(valor);

        const etiquetaNodo = document.createElement('p');
        etiquetaNodo.className = 'metric-label';
        etiquetaNodo.textContent = etiqueta;

        copia.appendChild(valorNodo);
        copia.appendChild(etiquetaNodo);

        if (detalle) {
            const detalleNodo = document.createElement('small');
            detalleNodo.className = 'seguimiento-report-summary-detail';
            detalleNodo.textContent = detalle;
            copia.appendChild(detalleNodo);
        }

        tarjeta.appendChild(iconoWrap);
        tarjeta.appendChild(copia);
        return tarjeta;
    }

    function obtenerDatosTabla(root) {
        const tabla = root.querySelector('table');
        if (!tabla) {
            return { tabla: null, encabezados: [], filas: [] };
        }

        const encabezados = Array.from(tabla.querySelectorAll('thead th')).map(function (th) {
            return th.textContent.trim();
        });
        const filas = Array.from(tabla.querySelectorAll('tbody tr'));
        return { tabla: tabla, encabezados: encabezados, filas: filas };
    }

    function indiceColumna(encabezados, nombre) {
        const buscado = nombre.toLowerCase();
        return encabezados.findIndex(function (encabezado) {
            return encabezado.toLowerCase() === buscado;
        });
    }

    function obtenerSeguimientosAtencion(datosTabla) {
        const indiceInstitucion = indiceColumna(datosTabla.encabezados, 'Institución');
        const indiceUltima = indiceColumna(datosTabla.encabezados, 'Última actividad');
        const indiceDias = indiceColumna(datosTabla.encabezados, 'Días sin actividad');
        const indiceEstatus = indiceColumna(datosTabla.encabezados, 'Estatus');
        const indiceAccion = indiceColumna(datosTabla.encabezados, 'Próxima acción');
        const resultados = [];

        datosTabla.filas.forEach(function (fila) {
            const celdas = Array.from(fila.cells);
            const ultima = indiceUltima >= 0 ? celdas[indiceUltima]?.textContent.trim() || '' : '';
            const diasTexto = indiceDias >= 0 ? celdas[indiceDias]?.textContent.trim() || '' : '';
            const dias = diasTexto === '—' ? null : numeroDesdeTexto(diasTexto);
            const sinActividad = ultima.toLowerCase().includes('sin actividad');
            const estancado = dias !== null && dias > 7;

            if (!sinActividad && !estancado) {
                return;
            }

            resultados.push({
                institucion: indiceInstitucion >= 0 ? celdas[indiceInstitucion]?.textContent.trim() || '—' : '—',
                estatus: indiceEstatus >= 0 ? celdas[indiceEstatus]?.textContent.trim() || '—' : '—',
                dias: sinActividad ? null : dias,
                accion: indiceAccion >= 0 ? celdas[indiceAccion]?.textContent.trim() || '—' : '—',
                sinActividad: sinActividad
            });
        });

        resultados.sort(function (a, b) {
            if (a.sinActividad !== b.sinActividad) {
                return a.sinActividad ? -1 : 1;
            }
            return (b.dias || 0) - (a.dias || 0);
        });

        return resultados;
    }

    function crearResumenOperativo(root, total, totalActividad, atencion) {
        const resumenAnterior = root.querySelector('section[aria-label="Indicadores del reporte"]');
        if (!resumenAnterior || root.querySelector('[data-operational-summary]')) {
            return;
        }

        resumenAnterior.classList.add('d-none');

        const promedio = total > 0 ? totalActividad / total : 0;
        const porcentajeAtencion = total > 0 ? (atencion.length / total) * 100 : 0;

        const seccion = document.createElement('section');
        seccion.className = 'seguimiento-report-operational mb-4';
        seccion.setAttribute('data-operational-summary', '');
        seccion.innerHTML = '<div class="seguimiento-report-section-heading"><div><span class="report-eyebrow">RESUMEN OPERATIVO</span><h3 class="panel-title mb-1">Situación actual del seguimiento</h3><p class="page-subtitle mb-0">Indicadores para dimensionar carga, actividad y necesidad de atención.</p></div></div>';

        const grid = document.createElement('div');
        grid.className = 'seguimiento-report-summary-grid';
        grid.appendChild(crearTarjetaResumen('bi-kanban', total, 'Seguimientos incluidos', 'Base del reporte generado'));
        grid.appendChild(crearTarjetaResumen('bi-activity', totalActividad, 'Interacciones registradas', 'Actividad del rango disponible'));
        grid.appendChild(crearTarjetaResumen('bi-calculator', promedio.toFixed(1), 'Interacciones por seguimiento', 'Promedio dentro del reporte'));
        grid.appendChild(crearTarjetaResumen('bi-exclamation-circle', atencion.length, 'Requieren atención', porcentajeAtencion.toFixed(1) + '% del total'));
        seccion.appendChild(grid);

        resumenAnterior.parentElement.insertBefore(seccion, resumenAnterior);
    }

    function crearPanelAtencion(root, atencion) {
        if (root.querySelector('[data-attention-panel]')) {
            return;
        }

        const resumen = root.querySelector('[data-operational-summary]');
        if (!resumen) {
            return;
        }

        const panel = document.createElement('section');
        panel.className = 'dashboard-panel seguimiento-report-attention mb-4';
        panel.setAttribute('data-attention-panel', '');

        const cabecera = document.createElement('div');
        cabecera.className = 'seguimiento-report-section-heading';
        cabecera.innerHTML = '<div><span class="report-eyebrow">ATENCIÓN REQUERIDA</span><h3 class="panel-title mb-1">Seguimientos que conviene revisar</h3><p class="page-subtitle mb-0">Se consideran los casos sin actividad registrada o con más de 7 días sin actividad.</p></div>';
        panel.appendChild(cabecera);

        if (atencion.length === 0) {
            const vacio = document.createElement('div');
            vacio.className = 'seguimiento-report-attention-empty';
            vacio.innerHTML = '<span><i class="bi bi-check2-circle"></i></span><div><strong>Sin alertas por inactividad</strong><p>No hay seguimientos sin actividad ni con más de 7 días sin movimiento dentro de este reporte.</p></div>';
            panel.appendChild(vacio);
        } else {
            const lista = document.createElement('div');
            lista.className = 'seguimiento-report-attention-list';
            atencion.slice(0, 5).forEach(function (item) {
                const fila = document.createElement('div');
                fila.className = 'seguimiento-report-attention-row';

                const causa = item.sinActividad
                    ? 'Sin actividad registrada'
                    : item.dias + ' días sin actividad';

                fila.innerHTML = '<div class="seguimiento-report-attention-main"><strong></strong><span></span></div><div class="seguimiento-report-attention-action"><small>Próxima acción</small><strong></strong></div>';
                fila.querySelector('.seguimiento-report-attention-main strong').textContent = item.institucion;
                fila.querySelector('.seguimiento-report-attention-main span').textContent = item.estatus + ' · ' + causa;
                fila.querySelector('.seguimiento-report-attention-action strong').textContent = item.accion;
                lista.appendChild(fila);
            });
            panel.appendChild(lista);
        }

        resumen.insertAdjacentElement('afterend', panel);
    }

    function actualizarDistribucion(panel, total, tituloNuevo, subtituloNuevo) {
        if (!panel) {
            return;
        }

        const titulo = panel.querySelector('.panel-title');
        if (titulo) {
            titulo.textContent = tituloNuevo;
        }

        if (!panel.querySelector('.seguimiento-report-panel-subtitle')) {
            const subtitulo = document.createElement('p');
            subtitulo.className = 'page-subtitle seguimiento-report-panel-subtitle';
            subtitulo.textContent = subtituloNuevo;
            titulo?.insertAdjacentElement('afterend', subtitulo);
        }

        const elementos = panel.querySelectorAll('.d-grid > div');
        elementos.forEach(function (item) {
            const cabecera = item.querySelector('.d-flex');
            const valor = cabecera?.querySelector('strong');
            const barra = item.querySelector('.progress-bar');
            const cantidad = numeroDesdeTexto(valor?.textContent || '0');
            const porcentaje = total > 0 ? (cantidad / total) * 100 : 0;

            if (valor) {
                valor.textContent = cantidad + ' · ' + porcentaje.toFixed(1) + '%';
            }
            if (barra) {
                barra.style.width = Math.min(100, porcentaje).toFixed(2) + '%';
            }
        });

        panel.classList.add('seguimiento-report-distribution-panel');
    }

    function refinarActividad(root, total, totalActividad) {
        const panel = root.querySelector('section[aria-labelledby="grafica-evolucion-actividad-titulo"]');
        if (!panel) {
            return;
        }

        const titulo = panel.querySelector('#grafica-evolucion-actividad-titulo');
        if (titulo) {
            titulo.textContent = 'Actividad reciente';
        }

        const parametros = new URLSearchParams(window.location.search);
        const tienePeriodo = Boolean(parametros.get('fecha_inicial') || parametros.get('fecha_final'));
        const subtitulo = titulo?.parentElement?.querySelector('.page-subtitle');
        if (subtitulo) {
            subtitulo.textContent = tienePeriodo
                ? 'Interacciones registradas durante el periodo seleccionado.'
                : 'Interacciones registradas en el rango de actividad disponible para los seguimientos seleccionados.';
        }

        const filaMetricas = panel.querySelector('.row.g-3.mb-4');
        const variacionTarjeta = filaMetricas ? buscarMetricaPorEtiqueta(filaMetricas, 'variación vs. periodo anterior') : null;
        const variacionTexto = variacionTarjeta?.querySelector('.metric-value')?.textContent.trim() || '';
        if (filaMetricas) {
            filaMetricas.classList.add('d-none');
        }

        if (!panel.querySelector('[data-activity-strip]')) {
            const strip = document.createElement('div');
            strip.className = 'seguimiento-report-activity-strip';
            strip.setAttribute('data-activity-strip', '');

            const promedio = total > 0 ? totalActividad / total : 0;
            const datos = [
                ['Interacciones', String(totalActividad)],
                ['Por seguimiento', promedio.toFixed(1)]
            ];

            if (variacionTexto && !variacionTexto.toLowerCase().includes('sin comparación')) {
                datos.push(['Vs. periodo anterior', variacionTexto]);
            }

            datos.forEach(function (dato) {
                const item = document.createElement('div');
                item.innerHTML = '<span></span><strong></strong>';
                item.querySelector('span').textContent = dato[0];
                item.querySelector('strong').textContent = dato[1];
                strip.appendChild(item);
            });

            const encabezado = panel.querySelector(':scope > .d-flex');
            encabezado?.insertAdjacentElement('afterend', strip);
        }

        const svg = panel.querySelector('svg');
        if (svg) {
            svg.parentElement?.classList.add('seguimiento-report-chart-wrap');
        }
        panel.classList.add('seguimiento-report-activity-panel');
    }

    function refinarDetalle(root, filtros) {
        const paneles = Array.from(root.querySelectorAll('section.dashboard-panel'));
        const detalle = paneles.find(function (panel) {
            return panel.querySelector('.panel-title')?.textContent.trim() === 'Detalle del reporte';
        });
        if (!detalle) {
            return;
        }

        const titulo = detalle.querySelector('.panel-title');
        titulo.textContent = 'Detalle de seguimientos';

        const header = detalle.querySelector('.table-panel-header > div');
        if (header && !header.querySelector('.seguimiento-report-detail-subtitle')) {
            const subtitulo = document.createElement('p');
            subtitulo.className = 'seguimiento-report-detail-subtitle';
            subtitulo.textContent = 'Consulta operativa de las instituciones incluidas en el reporte.';
            header.appendChild(subtitulo);
        }

        const tabla = detalle.querySelector('table');
        if (!tabla) {
            return;
        }

        const encabezados = Array.from(tabla.querySelectorAll('thead th')).map(function (th) {
            return th.textContent.trim();
        });

        const ocultarColumnas = [];
        const folio = indiceColumna(encabezados, 'Folio');
        if (folio >= 0) {
            ocultarColumnas.push(folio);
        }
        if ((filtros.Estado || 'Todos') !== 'Todos') {
            const estado = indiceColumna(encabezados, 'Estado');
            if (estado >= 0) {
                ocultarColumnas.push(estado);
            }
        }
        if ((filtros.Responsable || 'Todos') !== 'Todos') {
            const responsable = indiceColumna(encabezados, 'Responsable');
            if (responsable >= 0) {
                ocultarColumnas.push(responsable);
            }
        }

        ocultarColumnas.forEach(function (indice) {
            tabla.querySelectorAll('tr').forEach(function (fila) {
                if (fila.children[indice]) {
                    fila.children[indice].classList.add('d-none');
                }
            });
        });

        detalle.classList.add('seguimiento-report-detail-panel');
    }

    function refinarResultados(formulario) {
        const root = document.querySelector('section[aria-labelledby="titulo-reporte-seguimiento"]');
        if (!root || root.hasAttribute('data-decision-report-ready')) {
            return;
        }

        root.setAttribute('data-decision-report-ready', '');
        root.classList.add('seguimiento-report-results');

        const filtrosSection = root.querySelector('section[aria-label="Filtros utilizados"]');
        const filtros = obtenerMapaFiltros(filtrosSection);
        if (filtrosSection) {
            filtrosSection.classList.add('d-none');
        }

        crearContextoReporte(root, filtros);
        agregarBotonEditarFiltros(root, formulario);

        const summaryAnterior = root.querySelector('section[aria-label="Indicadores del reporte"]');
        const tarjetaTotal = summaryAnterior ? buscarMetricaPorEtiqueta(summaryAnterior, 'total de seguimientos') : null;
        const total = valorMetrica(tarjetaTotal) || numeroDesdeTexto(root.querySelector('.status-pill')?.textContent || '0');

        const actividadPanel = root.querySelector('section[aria-labelledby="grafica-evolucion-actividad-titulo"]');
        const tarjetaActividad = actividadPanel ? buscarMetricaPorEtiqueta(actividadPanel, 'actividades en el periodo') : null;
        const totalActividad = valorMetrica(tarjetaActividad);

        const datosTabla = obtenerDatosTabla(root);
        const atencion = obtenerSeguimientosAtencion(datosTabla);

        crearResumenOperativo(root, total, totalActividad, atencion);
        crearPanelAtencion(root, atencion);

        actualizarDistribucion(
            root.querySelector('section[aria-labelledby="grafica-estatus-titulo"]'),
            total,
            'Avance por estatus',
            'Distribución actual de los seguimientos incluidos.'
        );
        actualizarDistribucion(
            root.querySelector('section[aria-labelledby="grafica-municipios-titulo"]'),
            total,
            'Distribución territorial',
            'Participación de cada municipio dentro del reporte.'
        );

        refinarActividad(root, total, totalActividad);
        refinarDetalle(root, filtros);
    }

    function inicializarModal(formulario) {
        document.body.classList.add('seguimiento-reportes-page', 'seguimiento-reportes-modal-page');
        formulario.classList.add('seguimiento-report-filter-form', 'seguimiento-report-modal-form');

        refinarColumnasModal(formulario);
        refinarBotones(formulario);
        crearAyudaCompacta(formulario);
    }

    function inicializarPagina(formulario) {
        const panel = formulario.closest('section.dashboard-panel');

        if (!panel) {
            return;
        }

        document.body.classList.add('seguimiento-reportes-page');
        formulario.classList.add('seguimiento-report-filter-form');
        panel.classList.add('seguimiento-report-filter-panel');

        refinarEncabezado(panel);
        refinarBotones(formulario);
        crearAyudaCompacta(formulario);
        refinarRegreso(formulario);
        refinarTopbar();
        refinarExportacion();
        refinarResultados(formulario);
    }

    function inicializar() {
        if (!esReporteSeguimiento()) {
            return;
        }

        const formulario = document.querySelector('form[data-report-form]');
        if (!formulario) {
            return;
        }

        const parametros = new URLSearchParams(window.location.search);
        if (parametros.get('modal') === '1') {
            inicializarModal(formulario);
            return;
        }

        inicializarPagina(formulario);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', inicializar, { once: true });
    } else {
        inicializar();
    }
})();
