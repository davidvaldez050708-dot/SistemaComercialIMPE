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

    function ocultarProximaAccionIncorrecta(root) {
        const tabla = root.querySelector('.seguimiento-report-detail-panel table');
        if (!tabla) {
            return;
        }

        ocultarColumna(tabla, indiceColumna(tabla, 'Próxima acción'));

        root.querySelectorAll('.seguimiento-report-attention-action').forEach(function (accion) {
            accion.remove();
        });

        const subtituloAtencion = root.querySelector('[data-attention-panel] .seguimiento-report-section-heading .page-subtitle');
        if (subtituloAtencion) {
            subtituloAtencion.textContent = 'Se muestran los casos sin actividad registrada o con más de 7 días sin movimiento.';
        }
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
            subtitulo.textContent = 'Instituciones incluidas y estado actual de su seguimiento.';
        }

        ocultarProximaAccionIncorrecta(root);
        ocultarResponsableRedundante(root);
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
        compactarEncabezado(root);
        compactarResumen(root);
        compactarAtencionVacia(root);
        refinarActividad(root);
        refinarDetalle(root);
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
