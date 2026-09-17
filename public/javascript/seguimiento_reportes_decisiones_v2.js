(function () {
    'use strict';

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

                    celda.textContent = titulo !== '' ? titulo : '—';
                    celda.classList.remove('is-loading');
                    celda.dataset.flowNextAction = titulo;
                })
                .catch(function () {
                    celda.textContent = '—';
                    celda.classList.remove('is-loading');
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
        configurarEditarFiltros(root);
        sincronizarProximasAcciones(root);
    }

    function iniciar() {
        window.setTimeout(aplicar, 0);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', iniciar, { once: true });
    } else {
        iniciar();
    }
})();
