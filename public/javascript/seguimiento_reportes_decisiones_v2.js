(function () {
    'use strict';

    function esPaginaReporteSeguimiento() {
        const parametros = new URLSearchParams(window.location.search);
        return parametros.get('controller') === 'seguimientoVinculacionReporte';
    }

    function esReporteSeguimiento() {
        const parametros = new URLSearchParams(window.location.search);
        return parametros.get('controller') === 'seguimientoVinculacionReporte' && parametros.get('modal') !== '1';
    }

    function indiceColumna(tabla, nombre) {
        const buscado = String(nombre || '').trim().toLowerCase();
        return Array.from(tabla.querySelectorAll('thead th')).findIndex(function (th) {
            return th.textContent.trim().toLowerCase() === buscado;
        });
    }

    function ocultarColumna(tabla, indice) {
        if (indice < 0) {
            return;
        }

        tabla.querySelectorAll('tr').forEach(function (fila) {
            if (fila.children[indice]) {
                fila.children[indice].classList.add('d-none');
            }
        });
    }

    function normalizarOpcion(item) {
        return {
            valor: String(item && item.valor !== undefined ? item.valor : ''),
            etiqueta: String(item && item.etiqueta !== undefined ? item.etiqueta : '')
        };
    }

    function reemplazarOpciones(select, items, etiquetaGeneral, seleccionado, deshabilitarSinDatos) {
        if (!select) {
            return;
        }

        const valorSeleccionado = String(seleccionado === undefined || seleccionado === null ? '' : seleccionado);
        select.replaceChildren(new Option(etiquetaGeneral, select.name === 'municipio_id' || select.name === 'responsable_id' ? '0' : ''));

        (Array.isArray(items) ? items : []).map(normalizarOpcion).forEach(function (item) {
            const opcion = new Option(item.etiqueta, item.valor);
            opcion.selected = item.valor === valorSeleccionado;
            select.add(opcion);
        });

        if (valorSeleccionado !== '' && valorSeleccionado !== '0') {
            select.value = valorSeleccionado;
        }

        if (deshabilitarSinDatos) {
            select.disabled = (Array.isArray(items) ? items.length : 0) === 0;
        }
    }

    function ajustarEtiquetasFiltros(formulario) {
        const etiquetaDesde = formulario.querySelector('label[for="reporte_fecha_inicial"]');
        const etiquetaHasta = formulario.querySelector('label[for="reporte_fecha_final"]');
        const etiquetaCanal = formulario.querySelector('label[for="reporte_actividad"]');

        if (etiquetaDesde) {
            etiquetaDesde.textContent = 'Seguimiento iniciado desde';
        }
        if (etiquetaHasta) {
            etiquetaHasta.textContent = 'Seguimiento iniciado hasta';
        }
        if (etiquetaCanal) {
            etiquetaCanal.textContent = 'Último canal de contacto';
        }

        const ayudaCompacta = formulario.querySelector('[data-report-help-compact] span');
        if (ayudaCompacta) {
            ayudaCompacta.textContent = 'Las fechas filtran por la fecha de inicio del seguimiento. El canal corresponde a la última interacción registrada.';
        }
    }

    function configurarFiltrosDependientes() {
        const formulario = document.querySelector('form[data-report-form]');
        if (!formulario || formulario.hasAttribute('data-dependent-report-filters')) {
            return;
        }

        formulario.setAttribute('data-dependent-report-filters', '');
        ajustarEtiquetasFiltros(formulario);

        const estado = formulario.querySelector('#reporte_estado');
        const municipio = formulario.querySelector('#reporte_municipio');
        const institucion = formulario.querySelector('#reporte_institucion');
        const responsable = formulario.querySelector('#reporte_responsable');
        const estatus = formulario.querySelector('#reporte_estatus');
        const canal = formulario.querySelector('#reporte_actividad');
        const parametrosIniciales = new URLSearchParams(window.location.search);
        let institucionLegacy = parametrosIniciales.get('institucion') || '';
        let institucionInicial = parametrosIniciales.get('institucion_id') || '0';
        let solicitudActual = 0;

        if (institucion) {
            if (institucionInicial === '0' && institucionLegacy === '') {
                institucionLegacy = String(institucion.value || '');
            }
            institucion.name = 'institucion_id';
            institucion.dataset.reportInstitutionId = institucionInicial;
        }

        const columnaResponsable = responsable?.closest('.col-md-6, .col-12');

        const valor = function (select, respaldo) {
            return select ? String(select.value || respaldo || '') : String(respaldo || '');
        };

        const limpiarDesde = function (origen) {
            if (origen === 'estado') {
                if (municipio) municipio.value = '0';
                if (institucion) institucion.dataset.reportInstitutionId = '0';
            }
            if (origen === 'estado' || origen === 'municipio') {
                if (institucion) {
                    institucion.value = '';
                    institucion.dataset.reportInstitutionId = '0';
                }
            }
            if (origen === 'estado' || origen === 'municipio' || origen === 'institucion') {
                if (responsable) responsable.value = '0';
            }
            if (origen !== 'estatus') {
                if (estatus) estatus.value = '';
            }
            if (canal) canal.value = '';
            institucionLegacy = '';
        };

        const construirUrl = function () {
            const url = new URL(window.location.href);
            url.search = '';
            url.searchParams.set('controller', 'seguimientoVinculacionReporte');
            url.searchParams.set('action', 'opcionesFiltros');
            url.searchParams.set('estado_id', valor(estado, '0'));
            url.searchParams.set('municipio_id', valor(municipio, '0'));

            const institucionId = institucion
                ? String(institucion.dataset.reportInstitutionId || institucion.value || '0')
                : '0';
            if (/^\d+$/.test(institucionId) && Number(institucionId) > 0) {
                url.searchParams.set('institucion_id', institucionId);
            } else if (institucionLegacy !== '') {
                url.searchParams.set('institucion', institucionLegacy);
            }

            url.searchParams.set('responsable_id', valor(responsable, '0'));
            url.searchParams.set('estado_seguimiento', valor(estatus, ''));
            url.searchParams.set('tipo_actividad', valor(canal, ''));
            return url.toString();
        };

        const aplicarRespuesta = function (datos) {
            if (!datos || !datos.ok) {
                return;
            }

            const seleccion = datos.seleccion || {};
            reemplazarOpciones(
                municipio,
                datos.municipios,
                'Todos',
                String(seleccion.municipio_id || '0'),
                true
            );
            if (municipio) {
                municipio.disabled = String(seleccion.estado_id || '0') === '0' || !Array.isArray(datos.municipios) || datos.municipios.length === 0;
            }

            if (institucion) {
                reemplazarOpciones(
                    institucion,
                    datos.instituciones,
                    'Todas',
                    String(seleccion.institucion_id || '0'),
                    true
                );
                institucion.dataset.reportInstitutionId = String(seleccion.institucion_id || '0');
            }

            if (responsable) {
                reemplazarOpciones(
                    responsable,
                    datos.responsables,
                    'Todos',
                    String(seleccion.responsable_id || '0'),
                    true
                );
            }

            reemplazarOpciones(
                estatus,
                datos.estatus,
                'Todos',
                String(seleccion.estado_seguimiento || ''),
                true
            );
            reemplazarOpciones(
                canal,
                datos.canales,
                'Todos',
                String(seleccion.tipo_actividad || ''),
                true
            );

            if (responsable && columnaResponsable) {
                const mostrar = datos.mostrar_responsable !== false;
                columnaResponsable.classList.toggle('d-none', !mostrar);
                responsable.disabled = !mostrar || !Array.isArray(datos.responsables) || datos.responsables.length === 0;
                if (!mostrar) {
                    responsable.value = '0';
                }
            }

            institucionLegacy = '';
        };

        const actualizar = function () {
            const solicitud = ++solicitudActual;
            fetch(construirUrl(), {
                headers: { 'X-Requested-With': 'fetch' },
                cache: 'no-store'
            })
                .then(function (respuesta) {
                    if (!respuesta.ok) {
                        throw new Error('No fue posible actualizar los filtros.');
                    }
                    return respuesta.json();
                })
                .then(function (datos) {
                    if (solicitud !== solicitudActual) {
                        return;
                    }
                    aplicarRespuesta(datos);
                })
                .catch(function () {
                    // El formulario conserva las opciones renderizadas por el servidor como respaldo.
                });
        };

        estado?.addEventListener('change', function () {
            limpiarDesde('estado');
            actualizar();
        });
        municipio?.addEventListener('change', function () {
            limpiarDesde('municipio');
            actualizar();
        });
        institucion?.addEventListener('change', function () {
            institucion.dataset.reportInstitutionId = String(institucion.value || '0');
            limpiarDesde('institucion');
            institucion.dataset.reportInstitutionId = String(institucion.value || '0');
            actualizar();
        });
        responsable?.addEventListener('change', function () {
            limpiarDesde('responsable');
            actualizar();
        });
        estatus?.addEventListener('change', function () {
            limpiarDesde('estatus');
            actualizar();
        });

        formulario.addEventListener('submit', function () {
            if (institucion) {
                institucion.name = 'institucion_id';
                institucion.disabled = false;
            }
        });

        actualizar();
    }

    function ocultarResponsableRedundante(root) {
        const tabla = root.querySelector('.seguimiento-report-detail-panel table');
        if (!tabla) {
            return;
        }

        const indice = indiceColumna(tabla, 'Responsable');
        if (indice < 0) {
            return;
        }

        const valores = new Set();
        tabla.querySelectorAll('tbody tr').forEach(function (fila) {
            const valor = fila.children[indice]?.textContent.trim() || '';
            if (valor !== '') {
                valores.add(valor);
            }
        });

        const esAnalista = Number(window.IMPE_CURRENT_ROLE_ID || 0) === 4;
        if (esAnalista || valores.size <= 1) {
            ocultarColumna(tabla, indice);
        }
    }

    function compactarEncabezado(root) {
        const cabecera = root.querySelector(':scope > .d-flex');
        if (!cabecera) {
            return;
        }

        cabecera.classList.add('seguimiento-report-results-heading-v2');

        const contador = cabecera.querySelector('.status-pill');
        if (contador) {
            contador.classList.add('d-none');
        }

        const titulo = cabecera.querySelector('#titulo-reporte-seguimiento');
        if (titulo) {
            titulo.classList.add('seguimiento-report-main-title-v2');
        }

        const descripcion = titulo?.parentElement?.querySelector('.page-subtitle');
        if (descripcion) {
            descripcion.classList.add('d-none');
        }
    }

    function compactarResumen(root) {
        const resumen = root.querySelector('[data-operational-summary]');
        if (!resumen) {
            return;
        }

        const heading = resumen.querySelector('.seguimiento-report-section-heading');
        const eyebrow = heading?.querySelector('.report-eyebrow');
        const titulo = heading?.querySelector('.panel-title');
        const subtitulo = heading?.querySelector('.page-subtitle');

        if (eyebrow) {
            eyebrow.classList.add('d-none');
        }
        if (titulo) {
            titulo.textContent = 'Resumen operativo';
        }
        if (subtitulo) {
            subtitulo.textContent = 'Carga, actividad y seguimiento que requiere atención.';
        }

        const tarjetas = resumen.querySelectorAll('.seguimiento-report-summary-card');
        tarjetas.forEach(function (tarjeta) {
            const etiqueta = tarjeta.querySelector('.metric-label')?.textContent.trim().toLowerCase() || '';
            const detalle = tarjeta.querySelector('.seguimiento-report-summary-detail');

            if (!detalle) {
                return;
            }

            if (etiqueta.includes('interacciones registradas')) {
                detalle.textContent = 'Actividad registrada';
            }
            if (etiqueta.includes('seguimientos incluidos')) {
                detalle.textContent = 'Base analizada';
            }
        });
    }

    function compactarAtencionVacia(root) {
        const panel = root.querySelector('[data-attention-panel]');
        const vacio = panel?.querySelector('.seguimiento-report-attention-empty');
        if (!panel || !vacio) {
            return;
        }

        panel.classList.add('seguimiento-report-attention-compact-empty');
        panel.querySelector('.seguimiento-report-section-heading')?.remove();

        const titulo = vacio.querySelector('strong');
        const texto = vacio.querySelector('p');
        if (titulo) {
            titulo.textContent = 'Sin alertas por inactividad';
        }
        if (texto) {
            texto.textContent = 'Ningún seguimiento supera los 7 días sin actividad y todos cuentan con movimiento registrado.';
        }
    }

    function limpiarAccionIncorrectaAtencion(root) {
        root.querySelectorAll('.seguimiento-report-attention-action').forEach(function (accion) {
            accion.remove();
        });

        const subtituloAtencion = root.querySelector('[data-attention-panel] .seguimiento-report-section-heading .page-subtitle');
        if (subtituloAtencion) {
            subtituloAtencion.textContent = 'Se muestran los casos sin actividad registrada o con más de 7 días sin movimiento.';
        }
    }

    function formatearFechaIso(valor) {
        if (!/^\d{4}-\d{2}-\d{2}$/.test(valor || '')) {
            return '';
        }
        const partes = valor.split('-');
        const meses = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];
        const mes = meses[Math.max(0, Math.min(11, Number(partes[1]) - 1))];
        return Number(partes[2]) + ' ' + mes + ' ' + partes[0];
    }

    function obtenerRangoGrafica(panel) {
        const parametros = new URLSearchParams(window.location.search);
        const inicio = formatearFechaIso(parametros.get('fecha_inicial') || '');
        const fin = formatearFechaIso(parametros.get('fecha_final') || '');

        if (inicio || fin) {
            if (inicio && fin) {
                return 'del ' + inicio + ' al ' + fin;
            }
            if (inicio) {
                return 'desde el ' + inicio;
            }
            return 'hasta el ' + fin;
        }

        const etiquetas = Array.from(panel.querySelectorAll('svg text[text-anchor="middle"]'))
            .map(function (nodo) { return nodo.textContent.trim(); })
            .filter(Boolean);

        if (etiquetas.length >= 2) {
            return 'entre ' + etiquetas[0] + ' y ' + etiquetas[etiquetas.length - 1];
        }
        if (etiquetas.length === 1) {
            return 'en ' + etiquetas[0];
        }
        return '';
    }

    function refinarActividad(root) {
        const panel = root.querySelector('.seguimiento-report-activity-panel');
        if (!panel) {
            return;
        }

        panel.querySelector('[data-activity-strip]')?.remove();

        const titulo = panel.querySelector('#grafica-evolucion-actividad-titulo');
        const subtitulo = titulo?.parentElement?.querySelector('.page-subtitle');
        const rango = obtenerRangoGrafica(panel);

        if (subtitulo) {
            subtitulo.textContent = rango !== ''
                ? 'Interacciones registradas ' + rango + '.'
                : 'Evolución de las interacciones registradas para los seguimientos seleccionados.';
        }

        const wrap = panel.querySelector('.seguimiento-report-chart-wrap');
        if (wrap) {
            wrap.classList.add('seguimiento-report-chart-wrap-v2');
        }
    }

    function refinarDetalle(root) {
        const detalle = root.querySelector('.seguimiento-report-detail-panel');
        if (!detalle) {
            return;
        }

        const subtitulo = detalle.querySelector('.seguimiento-report-detail-subtitle');
        if (subtitulo) {
            subtitulo.textContent = 'Instituciones incluidas, estado actual y siguiente paso operativo.';
        }

        ocultarResponsableRedundante(root);
        limpiarAccionIncorrectaAtencion(root);
    }

    function textoCelda(fila, indice, respaldo) {
        if (!fila || indice < 0 || !fila.children[indice]) {
            return respaldo || '—';
        }
        const texto = fila.children[indice].textContent.trim();
        return texto !== '' ? texto : (respaldo || '—');
    }

    function ocultarDistribucionesRedundantes(root) {
        const ids = ['grafica-estatus-titulo', 'grafica-municipios-titulo'];
        const filas = new Set();

        ids.forEach(function (id) {
            const panel = root.querySelector('section[aria-labelledby="' + id + '"]');
            const columna = panel?.closest('.col-xl-6');
            if (columna) {
                columna.classList.add('d-none');
                const fila = columna.parentElement;
                if (fila) {
                    filas.add(fila);
                }
            }
        });

        filas.forEach(function (fila) {
            const columnasVisibles = Array.from(fila.children).filter(function (columna) {
                return !columna.classList.contains('d-none');
            });
            if (columnasVisibles.length === 0) {
                fila.classList.add('d-none');
            }
        });
    }

    function crearVistaInstitucion(root) {
        const parametros = new URLSearchParams(window.location.search);
        const institucionId = Number(parametros.get('institucion_id') || 0);
        if (!Number.isInteger(institucionId) || institucionId <= 0 || root.querySelector('[data-institution-focus]')) {
            return;
        }

        const tabla = root.querySelector('.seguimiento-report-detail-panel table');
        const fila = tabla?.querySelector('tbody tr');
        if (!tabla || !fila) {
            return;
        }

        const indiceInstitucion = indiceColumna(tabla, 'Institución');
        const indiceEstado = indiceColumna(tabla, 'Estado');
        const indiceMunicipio = indiceColumna(tabla, 'Municipio');
        const indiceResponsable = indiceColumna(tabla, 'Responsable');
        const indiceUltima = indiceColumna(tabla, 'Última actividad');
        const indiceEstatus = indiceColumna(tabla, 'Estatus');
        const indiceDias = indiceColumna(tabla, 'Días sin actividad');
        const indiceAccion = indiceColumna(tabla, 'Próxima acción');

        const nombre = textoCelda(fila, indiceInstitucion, 'Institución seleccionada');
        const estado = textoCelda(fila, indiceEstado, '—');
        const municipio = textoCelda(fila, indiceMunicipio, '—');
        const responsable = textoCelda(fila, indiceResponsable, '—');
        const ultima = textoCelda(fila, indiceUltima, 'Sin actividad registrada');
        const estatus = textoCelda(fila, indiceEstatus, 'Sin estado');
        const diasTexto = textoCelda(fila, indiceDias, '—');
        const dias = diasTexto === '—' ? 'Sin actividad registrada' : diasTexto + (diasTexto === '1' ? ' día' : ' días');
        const accion = textoCelda(fila, indiceAccion, '—');

        const contexto = root.querySelector('[data-report-context]');
        if (contexto) {
            const ubicacion = [municipio !== '—' ? municipio : '', estado !== '—' ? estado : ''].filter(Boolean).join(', ');
            contexto.textContent = [nombre, ubicacion, responsable !== '—' ? responsable : ''].filter(Boolean).join(' · ');
        }

        const panel = document.createElement('section');
        panel.className = 'dashboard-panel mb-4';
        panel.setAttribute('data-institution-focus', '');
        panel.innerHTML =
            '<div class="seguimiento-report-section-heading mb-3">' +
                '<div>' +
                    '<span class="report-eyebrow">INSTITUCIÓN SELECCIONADA</span>' +
                    '<h3 class="panel-title mb-1"></h3>' +
                    '<p class="page-subtitle mb-0">Estado actual y siguiente paso del seguimiento.</p>' +
                '</div>' +
            '</div>' +
            '<div class="row g-3" data-institution-focus-grid></div>';

        panel.querySelector('.panel-title').textContent = nombre;
        const grid = panel.querySelector('[data-institution-focus-grid]');
        const datos = [
            ['Ubicación', [municipio, estado].filter(function (item) { return item && item !== '—'; }).join(', ') || '—', 'col-md-6 col-xl-4'],
            ['Responsable', responsable, 'col-md-6 col-xl-4'],
            ['Estatus actual', estatus, 'col-md-6 col-xl-4'],
            ['Última actividad', ultima, 'col-md-6 col-xl-4'],
            ['Días sin actividad', dias, 'col-md-6 col-xl-4'],
            ['Próxima acción', accion, 'col-md-6 col-xl-4']
        ];

        datos.forEach(function (dato, indice) {
            const columna = document.createElement('div');
            columna.className = dato[2];
            const caja = document.createElement('div');
            caja.className = 'border rounded-3 p-3 h-100 bg-white';
            const etiqueta = document.createElement('small');
            etiqueta.className = 'd-block text-muted fw-semibold mb-1';
            etiqueta.textContent = dato[0];
            const valor = document.createElement('strong');
            valor.className = 'd-block';
            valor.textContent = dato[1];
            if (indice === 5) {
                valor.setAttribute('data-institution-next-action', '');
            }
            caja.appendChild(etiqueta);
            caja.appendChild(valor);
            columna.appendChild(caja);
            grid.appendChild(columna);
        });

        const resumen = root.querySelector('[data-operational-summary]');
        if (resumen && resumen.parentElement) {
            resumen.parentElement.insertBefore(panel, resumen);
        } else {
            root.prepend(panel);
        }

        ocultarColumna(tabla, indiceInstitucion);
        ocultarDistribucionesRedundantes(root);

        const detalle = root.querySelector('.seguimiento-report-detail-panel');
        const tituloDetalle = detalle?.querySelector('.panel-title');
        const subtituloDetalle = detalle?.querySelector('.seguimiento-report-detail-subtitle');
        if (tituloDetalle) {
            tituloDetalle.textContent = 'Detalle del seguimiento';
        }
        if (subtituloDetalle) {
            subtituloDetalle.textContent = 'Información operativa de la institución seleccionada.';
        }
    }

    function ajustarAnaliticaInstitucion(root) {
        const parametros = new URLSearchParams(window.location.search);
        if (Number(parametros.get('institucion_id') || 0) <= 0) {
            return;
        }

        const panel = root.querySelector('.seguimiento-report-flow-panel');
        const titulo = panel?.querySelector('.panel-title');
        const subtitulo = panel?.querySelector('.page-subtitle');
        if (titulo) {
            titulo.textContent = 'Etapa actual de vinculación';
        }
        if (subtitulo) {
            subtitulo.textContent = 'Punto operativo actual de la institución seleccionada.';
        }
    }

    function sincronizarProximasAcciones(root) {
        const tabla = root.querySelector('.seguimiento-report-detail-panel table');
        if (!tabla) {
            return;
        }

        const indiceAccion = indiceColumna(tabla, 'Próxima acción');
        if (indiceAccion < 0) {
            return;
        }

        const ids = Array.isArray(window.IMPE_REPORTE_SEGUIMIENTO_IDS)
            ? window.IMPE_REPORTE_SEGUIMIENTO_IDS
            : [];
        const filas = Array.from(tabla.querySelectorAll('tbody tr'));

        filas.forEach(function (fila, indiceFila) {
            const celda = fila.children[indiceAccion];
            const seguimientoId = Number(ids[indiceFila] || 0);

            if (!celda) {
                return;
            }

            celda.classList.add('seguimiento-report-next-action-cell', 'is-loading');
            celda.textContent = seguimientoId > 0 ? 'Consultando…' : '—';

            if (seguimientoId <= 0) {
                celda.classList.remove('is-loading');
                return;
            }

            fetch(
                'index.php?controller=seguimientoFlujo&action=estado&seguimiento_id=' +
                encodeURIComponent(seguimientoId),
                {
                    headers: { 'X-Requested-With': 'fetch' },
                    cache: 'no-store'
                }
            )
                .then(function (respuesta) {
                    if (!respuesta.ok) {
                        throw new Error('No fue posible consultar la ruta del seguimiento.');
                    }
                    return respuesta.json();
                })
                .then(function (datos) {
                    const titulo = datos && datos.ok && datos.flujo
                        ? String(datos.flujo.titulo || datos.flujo.accion_principal?.etiqueta || '').trim()
                        : '';
                    const texto = titulo !== '' ? titulo : '—';

                    celda.textContent = texto;
                    celda.classList.remove('is-loading');
                    celda.dataset.flowNextAction = titulo;
                    if (indiceFila === 0) {
                        const foco = root.querySelector('[data-institution-next-action]');
                        if (foco) {
                            foco.textContent = texto;
                        }
                    }
                })
                .catch(function () {
                    celda.textContent = '—';
                    celda.classList.remove('is-loading');
                    if (indiceFila === 0) {
                        const foco = root.querySelector('[data-institution-next-action]');
                        if (foco) {
                            foco.textContent = '—';
                        }
                    }
                });
        });
    }

    function ocultarFiltrosTrasGenerar() {
        const formulario = document.querySelector('form[data-report-form]');
        const panel = formulario?.closest('section.dashboard-panel');

        if (panel) {
            panel.classList.add('d-none');
            panel.setAttribute('aria-hidden', 'true');
        }
    }

    function urlModalFiltros() {
        const url = new URL(window.location.href);
        url.searchParams.set('modal', '1');
        return url.toString();
    }

    function asegurarOrigenEnFormularioModal(iframe) {
        const origen = new URL(window.location.href).searchParams.get('origen');
        if (!origen || !iframe.contentDocument) {
            return;
        }

        const formulario = iframe.contentDocument.querySelector('form[data-report-form]');
        if (!formulario) {
            return;
        }

        let input = formulario.querySelector('input[name="origen"]');
        if (!input) {
            input = iframe.contentDocument.createElement('input');
            input.type = 'hidden';
            input.name = 'origen';
            formulario.appendChild(input);
        }
        input.value = origen;
    }

    function crearModalEditarFiltros() {
        let modal = document.getElementById('modalEditarFiltrosReporteSeguimiento');
        if (modal) {
            return modal;
        }

        modal = document.createElement('div');
        modal.className = 'modal fade seguimiento-report-edit-modal';
        modal.id = 'modalEditarFiltrosReporteSeguimiento';
        modal.tabIndex = -1;
        modal.setAttribute('aria-labelledby', 'modalEditarFiltrosReporteSeguimientoTitulo');
        modal.setAttribute('aria-hidden', 'true');
        modal.innerHTML =
            '<div class="modal-dialog modal-dialog-centered modal-xl modal-fullscreen-sm-down">' +
                '<div class="modal-content system-form-modal">' +
                    '<div class="modal-header system-form-modal-header">' +
                        '<div>' +
                            '<h5 class="system-form-modal-title" id="modalEditarFiltrosReporteSeguimientoTitulo">Editar filtros del reporte</h5>' +
                            '<p class="system-form-modal-subtitle">Ajusta los criterios y vuelve a generar el reporte.</p>' +
                        '</div>' +
                        '<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>' +
                    '</div>' +
                    '<div class="modal-body p-0 overflow-hidden">' +
                        '<iframe class="seguimiento-report-edit-iframe" title="Editar filtros del reporte de seguimiento"></iframe>' +
                    '</div>' +
                '</div>' +
            '</div>';

        const iframe = modal.querySelector('.seguimiento-report-edit-iframe');
        iframe?.addEventListener('load', function () {
            try {
                asegurarOrigenEnFormularioModal(iframe);
            } catch (error) {
                // El formulario sigue siendo utilizable aunque no pueda conservar el origen.
            }
        });

        document.body.appendChild(modal);
        return modal;
    }

    function configurarEditarFiltros(root) {
        const botonAnterior = root.querySelector('[data-edit-report-filters]');
        if (!botonAnterior) {
            return;
        }

        const boton = botonAnterior.cloneNode(true);
        botonAnterior.replaceWith(boton);

        boton.addEventListener('click', function () {
            const modal = crearModalEditarFiltros();
            const iframe = modal.querySelector('.seguimiento-report-edit-iframe');
            if (iframe) {
                iframe.src = urlModalFiltros();
            }

            if (window.bootstrap && window.bootstrap.Modal) {
                window.bootstrap.Modal.getOrCreateInstance(modal).show();
            }
        });
    }

    function aplicar() {
        if (!esReporteSeguimiento()) {
            return;
        }

        const root = document.querySelector('section[aria-labelledby="titulo-reporte-seguimiento"]');
        if (!root || root.hasAttribute('data-decision-report-v2')) {
            return;
        }

        root.setAttribute('data-decision-report-v2', '');
        ocultarFiltrosTrasGenerar();
        compactarEncabezado(root);
        compactarResumen(root);
        compactarAtencionVacia(root);
        refinarActividad(root);
        refinarDetalle(root);
        crearVistaInstitucion(root);
        configurarEditarFiltros(root);
        sincronizarProximasAcciones(root);
        window.setTimeout(function () { ajustarAnaliticaInstitucion(root); }, 500);
        window.setTimeout(function () { ajustarAnaliticaInstitucion(root); }, 1400);
    }

    function iniciar() {
        if (!esPaginaReporteSeguimiento()) {
            return;
        }

        configurarFiltrosDependientes();
        window.setTimeout(aplicar, 0);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', iniciar, { once: true });
    } else {
        iniciar();
    }
})();
