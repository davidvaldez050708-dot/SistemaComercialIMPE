document.addEventListener('DOMContentLoaded', function () {
    const seccionEducacion = document.getElementById('educacion');

    if (!seccionEducacion) {
        return;
    }

    const botonActualizacionOficial = document.getElementById('botonActualizarInformacionOficial');
    const parametrosUrl = new URLSearchParams(window.location.search);
    const estadoId = String(
        botonActualizacionOficial?.dataset.estadoId ||
        parametrosUrl.get('estado_id') ||
        ''
    ).trim();

    if (!/^\d+$/.test(estadoId) || Number(estadoId) <= 0) {
        return;
    }

    const endpoint = window.location.pathname;
    const escapar = function (valor) {
        return String(valor ?? '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    };
    const numero = function (valor) {
        const n = Number(valor);
        return Number.isFinite(n)
            ? new Intl.NumberFormat('es-MX', { maximumFractionDigits: 0 }).format(n)
            : '—';
    };
    const decimal = function (valor) {
        const n = Number(valor);
        return Number.isFinite(n)
            ? new Intl.NumberFormat('es-MX', {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2
            }).format(n)
            : '—';
    };
    const fecha = function (valor) {
        if (!valor) {
            return '—';
        }

        const normalizado = String(valor).replace(' ', 'T');
        const objeto = new Date(normalizado);

        if (Number.isNaN(objeto.getTime())) {
            return String(valor);
        }

        return new Intl.DateTimeFormat('es-MX', {
            year: 'numeric',
            month: 'short',
            day: '2-digit',
            hour: '2-digit',
            minute: '2-digit'
        }).format(objeto);
    };

    const bloque = document.createElement('div');
    bloque.className = 'data-target-education';
    bloque.setAttribute('data-target-education', '');
    bloque.innerHTML =
        '<div class="data-target-education-loading">' +
            '<span class="spinner-border spinner-border-sm" aria-hidden="true"></span>' +
            '<span>Cargando población objetivo educativa...</span>' +
        '</div>';

    const rezagoOficial = seccionEducacion.querySelector('.data-education-official');

    if (rezagoOficial) {
        rezagoOficial.insertAdjacentElement('beforebegin', bloque);
    } else {
        seccionEducacion.appendChild(bloque);
    }

    const renderizar = function (datos) {
        if (!datos || datos.disponible !== true) {
            const migracionPendiente = datos?.migracion_pendiente === true;
            bloque.innerHTML =
                '<div class="data-target-education-heading">' +
                    '<div>' +
                        '<span class="data-target-education-kicker">POBLACIÓN OBJETIVO EDUCATIVA</span>' +
                        '<h4>Secundaria completa</h4>' +
                        '<p>Población de 15 años y más con secundaria completa según la fuente oficial de INEGI.</p>' +
                    '</div>' +
                '</div>' +
                '<p class="data-target-education-empty">' +
                    (migracionPendiente
                        ? 'La estructura está preparada, pero falta aplicar la migración de base de datos para comenzar a guardar este indicador.'
                        : 'Aún no hay información oficial registrada. Usa “Actualizar información oficial” para consultarla desde INEGI.') +
                '</p>' +
                '<p class="data-target-education-note">' +
                    '<i class="bi bi-info-circle" aria-hidden="true"></i>' +
                    'Este indicador es una aproximación inicial al universo de continuidad educativa. ' +
                    'Por sí solo no confirma que una persona actualmente no estudie.' +
                '</p>';
            return;
        }

        const historico = Array.isArray(datos.historico) ? datos.historico : [];
        const historicoHtml = historico.length > 1
            ? '<div class="data-target-education-source"><span>Periodos guardados: <strong>' +
                escapar(historico.map(function (item) { return item.anio; }).join(', ')) +
                '</strong></span></div>'
            : '';

        bloque.innerHTML =
            '<div class="data-target-education-heading">' +
                '<div>' +
                    '<span class="data-target-education-kicker">POBLACIÓN OBJETIVO EDUCATIVA</span>' +
                    '<h4>' + escapar(datos.nombre_indicador || 'Población de 15 años y más con secundaria completa') + '</h4>' +
                    '<p>Permite dimensionar a la población que ya concluyó secundaria como punto de partida para analizar oportunidades de continuidad educativa.</p>' +
                '</div>' +
                '<span class="data-target-education-period">' + escapar(datos.anio || '') + '</span>' +
            '</div>' +
            '<div class="data-target-education-metrics">' +
                '<article class="data-target-education-metric">' +
                    '<span>Personas con secundaria completa</span>' +
                    '<strong>' + numero(datos.cantidad_personas) + '</strong>' +
                    '<small>' + escapar(datos.grupo_edad || '15 años y más') + '</small>' +
                '</article>' +
                '<article class="data-target-education-metric">' +
                    '<span>Proporción sobre población de 15 años y más</span>' +
                    '<strong>' + decimal(datos.porcentaje) + ' %</strong>' +
                    '<small>Calculado con P15SEC_CO / P_15YMAS del mismo archivo oficial.</small>' +
                '</article>' +
                '<article class="data-target-education-metric">' +
                    '<span>Población base de 15 años y más</span>' +
                    '<strong>' + numero(datos.poblacion_base) + '</strong>' +
                    '<small>Denominador utilizado para calcular la proporción.</small>' +
                '</article>' +
            '</div>' +
            '<p class="data-target-education-note">' +
                '<i class="bi bi-info-circle" aria-hidden="true"></i>' +
                'Secundaria completa no equivale automáticamente a “no continúa estudiando”. ' +
                'La condición de asistencia escolar se integrará como un indicador adicional cuando se incorpore esa variable oficial.' +
            '</p>' +
            '<div class="data-target-education-source">' +
                '<span>Fuente: <strong>' + escapar(datos.fuente || 'INEGI') + '</strong></span>' +
                '<span>Última consulta: <strong>' + escapar(fecha(datos.fecha_consulta)) + '</strong></span>' +
                '<span>Actualización: <strong>Automática</strong></span>' +
            '</div>' +
            historicoHtml;
    };

    const cargar = async function () {
        try {
            const respuesta = await fetch(
                endpoint + '?controller=poblacionObjetivoEducativa&action=obtener&estado_id=' +
                    encodeURIComponent(estadoId),
                {
                    headers: {
                        'X-Requested-With': 'fetch'
                    }
                }
            );
            const resultado = await respuesta.json();

            if (!respuesta.ok || resultado.ok !== true) {
                throw new Error(resultado.mensaje || 'No fue posible cargar la población objetivo educativa.');
            }

            renderizar(resultado.datos || {});
        } catch (error) {
            bloque.innerHTML =
                '<div class="data-target-education-heading">' +
                    '<div>' +
                        '<span class="data-target-education-kicker">POBLACIÓN OBJETIVO EDUCATIVA</span>' +
                        '<h4>Secundaria completa</h4>' +
                    '</div>' +
                '</div>' +
                '<p class="data-target-education-empty">' +
                    escapar(error.message || 'No fue posible cargar este indicador.') +
                '</p>';
        }
    };

    const prepararActualizacion = function () {
        const modal = document.getElementById('modalActualizarInformacionOficial');
        const inicial = modal?.querySelector('[data-official-initial="individual"]');

        if (!modal || !inicial || inicial.querySelector('[data-target-update-panel]')) {
            return;
        }

        const panel = document.createElement('div');
        panel.className = 'data-target-update-panel';
        panel.setAttribute('data-target-update-panel', '');
        panel.innerHTML =
            '<div class="data-target-update-panel-head">' +
                '<div>' +
                    '<strong>Población objetivo educativa</strong>' +
                    '<p>Consulta automáticamente el Censo 2020 de INEGI/ITER para obtener P15SEC_CO y P_15YMAS del Estado.</p>' +
                '</div>' +
                '<button type="button" class="btn btn-system-light" data-target-update-button>' +
                    '<i class="bi bi-mortarboard me-2" aria-hidden="true"></i>' +
                    '<span>Actualizar desde INEGI</span>' +
                '</button>' +
            '</div>' +
            '<div class="data-target-update-status d-none" data-target-update-status role="status"></div>';
        inicial.appendChild(panel);

        const boton = panel.querySelector('[data-target-update-button]');
        const estado = panel.querySelector('[data-target-update-status]');

        boton?.addEventListener('click', async function () {
            boton.disabled = true;
            const textoOriginal = boton.innerHTML;
            boton.innerHTML =
                '<span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>' +
                'Consultando INEGI...';
            estado.className = 'data-target-update-status';
            estado.textContent = 'Descargando y validando el archivo oficial del Estado...';

            const datos = new URLSearchParams();
            datos.set('estado_id', estadoId);

            try {
                const respuesta = await fetch(
                    endpoint + '?controller=poblacionObjetivoEducativa&action=actualizar',
                    {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
                            'X-Requested-With': 'fetch'
                        },
                        body: datos.toString()
                    }
                );
                const resultado = await respuesta.json();

                if (!respuesta.ok || resultado.ok !== true) {
                    throw new Error(resultado.mensaje || 'No fue posible actualizar el indicador educativo.');
                }

                estado.className = 'data-target-update-status is-success';
                estado.textContent = resultado.mensaje || 'Información educativa actualizada correctamente.';
                renderizar(resultado.datos || {});
            } catch (error) {
                estado.className = 'data-target-update-status is-error';
                estado.textContent = error.message || 'No fue posible actualizar la información educativa.';
            } finally {
                boton.disabled = false;
                boton.innerHTML = textoOriginal;
            }
        });
    };

    prepararActualizacion();
    cargar();
});
