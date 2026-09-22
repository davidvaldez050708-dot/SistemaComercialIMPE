(function () {
    'use strict';

    const PREFIJO_PERSISTENTE_ANTERIOR = 'impe:seguimiento:ruta:';
    const VIGENCIA_MS = 20 * 1000;
    const memoria = new Map();
    const fetchOriginal = window.fetch.bind(window);

    const limpiarPersistenciaAnterior = function () {
        try {
            const borrar = [];
            for (let indice = 0; indice < window.sessionStorage.length; indice += 1) {
                const item = window.sessionStorage.key(indice);
                if (item && item.startsWith(PREFIJO_PERSISTENTE_ANTERIOR)) {
                    borrar.push(item);
                }
            }
            borrar.forEach(function (item) {
                window.sessionStorage.removeItem(item);
            });
        } catch (error) {
            // La ruta operativa ya no depende de almacenamiento persistente.
        }
    };

    limpiarPersistenciaAnterior();

    const estilos = document.createElement('style');
    estilos.textContent =
        '[data-linkage-follow-row] [data-row-next-action]:not([data-route-next-ready="1"]){visibility:hidden;}' +
        '[data-route-summary-count]:not([data-route-summary-count="en_seguimiento"]):not([data-route-summary-ready="1"]){visibility:hidden;}';
    document.head.appendChild(estilos);

    const guardar = function (seguimientoId, flujo) {
        seguimientoId = Number(seguimientoId || flujo?.seguimiento_id || 0);
        if (seguimientoId <= 0 || !flujo || typeof flujo !== 'object') {
            return;
        }

        const registro = {
            guardado_at: Date.now(),
            flujo: flujo
        };

        memoria.set(seguimientoId, registro);
    };

    const rutasIniciales = window.IMPE_SEGUIMIENTO_RUTAS_INICIALES || {};
    Object.keys(rutasIniciales).forEach(function (clave) {
        const seguimientoId = Number(clave || 0);
        const flujo = rutasIniciales[clave];

        if (seguimientoId > 0 && flujo && typeof flujo === 'object') {
            guardar(seguimientoId, flujo);
        }
    });

    const obtenerRegistro = function (seguimientoId) {
        seguimientoId = Number(seguimientoId || 0);
        if (seguimientoId <= 0) {
            return null;
        }

        const registro = memoria.get(seguimientoId) || null;

        if (
            !registro ||
            !registro.flujo ||
            (Date.now() - Number(registro.guardado_at || 0)) > VIGENCIA_MS
        ) {
            memoria.delete(seguimientoId);
            return null;
        }

        return registro;
    };

    const obtener = function (seguimientoId) {
        return obtenerRegistro(seguimientoId)?.flujo || null;
    };

    const edad = function (seguimientoId) {
        const registro = obtenerRegistro(seguimientoId);
        return registro
            ? Math.max(0, Date.now() - Number(registro.guardado_at || 0))
            : null;
    };

    const invalidar = function (seguimientoId) {
        seguimientoId = Number(seguimientoId || 0);

        if (seguimientoId > 0) {
            memoria.delete(seguimientoId);
            return;
        }

        memoria.clear();
    };

    const etiquetaEtapa = function (pasoActual, tituloFlujo, fila) {
        const estadoInterno = String(
            fila?.dataset.internalStage || fila?.dataset.stage || ''
        ).trim().toUpperCase();
        const titulo = String(tituloFlujo || '').trim().toLowerCase();

        if (estadoInterno === 'DESCARTADO') {
            return 'Descartado';
        }

        if (String(fila?.dataset.ally || '') === '1') {
            return 'Aliado';
        }

        if (Number(pasoActual) === 12) {
            if (titulo.includes('programad')) {
                return 'Reunión programada';
            }
            if (titulo.includes('seguimiento de acuerdos') || titulo.includes('dar seguimiento')) {
                return 'Seguimiento de acuerdos';
            }
            return 'Reunión y acuerdos';
        }

        const pasos = {
            1: 'Seguimiento iniciado',
            2: 'Investigación de datos',
            3: 'Contacto y validación',
            4: 'Datos verificados',
            5: 'Oficio preparado',
            6: 'PDF generado',
            7: 'Oficio / correo enviado',
            8: 'Esperando respuesta',
            9: 'Respuesta recibida',
            10: 'Seguimiento por correo',
            11: 'Reunión agendada',
            13: 'Convenio'
        };

        return pasos[Number(pasoActual)] || '';
    };

    const marcarResumenListo = function () {
        const filas = Array.from(document.querySelectorAll('[data-linkage-follow-row]'));
        const activas = filas.filter(function (fila) {
            const estado = String(
                fila.dataset.internalStage || fila.dataset.stage || ''
            ).trim().toUpperCase();
            return estado !== 'DESCARTADO';
        });
        const resueltas = activas.every(function (fila) {
            const paso = Number(fila.dataset.flowStep || 0);
            return paso >= 1 && paso <= 13;
        });

        if (!resueltas) {
            return;
        }

        document.querySelectorAll('[data-route-summary-count]').forEach(function (elemento) {
            elemento.dataset.routeSummaryReady = '1';
        });
    };

    const aplicarFila = function (seguimientoId, flujo, emitirEvento) {
        seguimientoId = Number(seguimientoId || flujo?.seguimiento_id || 0);
        const boton = document.querySelector('[data-work-follow-id="' + seguimientoId + '"]');
        const fila = boton?.closest('[data-linkage-follow-row]');

        if (!fila || !flujo) {
            return false;
        }

        if (!fila.dataset.internalStage && fila.dataset.stage) {
            fila.dataset.internalStage = fila.dataset.stage;
        }

        const pasoActual = Number(flujo.paso_actual || 0);
        const titulo = String(flujo.titulo || '').trim();
        const esAliado = Boolean(flujo.contexto?.es_aliado);
        const etapa = fila.querySelector('[data-row-stage-label]');
        const proxima = fila.querySelector('[data-row-next-action]');
        let cambio = false;

        fila.dataset.ally = esAliado ? '1' : '0';
        fila.classList.toggle('is-ally', esAliado);

        if (pasoActual > 0) {
            if (String(fila.dataset.flowStep || '') !== String(pasoActual)) {
                fila.dataset.flowStep = String(pasoActual);
                cambio = true;
            }

            const etiqueta = etiquetaEtapa(pasoActual, titulo, fila);
            if (etapa && etiqueta !== '') {
                fila.dataset.flowStageLabel = etiqueta;
                etapa.classList.toggle('is-ally', esAliado);
                if (String(etapa.textContent || '').trim() !== etiqueta) {
                    etapa.textContent = etiqueta;
                    cambio = true;
                }
                etapa.title = esAliado
                    ? 'Convenio formalizado · Paso 13 de 13'
                    : 'Paso ' + pasoActual + ' de 13';
                etapa.dataset.routeStageReady = '1';
            }
        }

        if (titulo !== '') {
            if (String(fila.dataset.flowTitle || '') !== titulo) {
                fila.dataset.flowTitle = titulo;
                cambio = true;
            }

            if (proxima) {
                const siguienteTexto = esAliado ? 'Sin acción pendiente' : titulo;
                proxima.dataset.flowNextAction = siguienteTexto;
                if (String(proxima.textContent || '').trim() !== siguienteTexto) {
                    proxima.textContent = siguienteTexto;
                    cambio = true;
                }
                proxima.dataset.routeNextReady = '1';
            }
        }

        if (boton) {
            boton.classList.toggle('is-ally', esAliado);
            boton.title = esAliado ? 'Ver expediente del aliado' : 'Trabajar seguimiento';
            boton.setAttribute(
                'aria-label',
                esAliado ? 'Ver expediente del aliado' : 'Trabajar seguimiento'
            );
            const icono = boton.querySelector('i');
            const textoBoton = boton.querySelector('span');
            if (icono) {
                icono.className = esAliado ? 'bi bi-folder2-open' : 'bi bi-kanban';
            }
            if (textoBoton) {
                textoBoton.textContent = esAliado ? 'Ver expediente' : 'Trabajar';
            }
        }

        if (cambio && emitirEvento !== false) {
            document.dispatchEvent(new CustomEvent('impe:flow-row-updated', {
                detail: {
                    seguimientoId: seguimientoId,
                    pasoActual: pasoActual,
                    titulo: titulo,
                    esAliado: esAliado
                }
            }));
        }

        marcarResumenListo();
        return cambio;
    };

    const escapar = function (valor) {
        const div = document.createElement('div');
        div.textContent = String(valor || '');
        return div.innerHTML;
    };

    const renderizarNodo = function (paso, tipo) {
        if (!paso) {
            return '';
        }

        const clases = ['linkage-flow-node'];
        if (tipo === 'actual') {
            clases.push('is-current');
        }
        if (tipo === 'anterior') {
            clases.push('is-complete');
        }

        const etiqueta = tipo === 'anterior'
            ? 'Anterior'
            : (tipo === 'actual' ? 'Actual' : 'Siguiente');

        return '<div class="' + clases.join(' ') + '">' +
            '<span>' + etiqueta + '</span>' +
            '<strong>' + escapar(paso.titulo || '—') + '</strong>' +
        '</div>';
    };

    const botonAccion = function (accion, principal) {
        if (!accion || !accion.codigo) {
            return '';
        }

        return '<button type="button" class="' +
            (principal ? 'btn btn-system-save' : 'btn btn-system-light') +
            '" data-flow-action="' + escapar(accion.codigo) + '">' +
            '<i class="bi ' + escapar(accion.icono || 'bi-arrow-right') + '"></i>' +
            '<span>' + escapar(accion.etiqueta || 'Continuar') + '</span>' +
        '</button>';
    };

    const obtenerOCrearBloque = function (offcanvas) {
        let bloque = offcanvas.querySelector('[data-work-flow-section]');
        if (bloque) {
            return bloque;
        }

        const referencia = offcanvas.querySelector('[data-work-next-section]');
        if (!referencia) {
            return null;
        }

        bloque = document.createElement('section');
        bloque.className = 'linkage-work-section d-none';
        bloque.setAttribute('data-work-flow-section', '');
        bloque.innerHTML =
            '<div class="linkage-flow-heading">' +
                '<h3>Ruta de vinculación</h3>' +
                '<span class="linkage-flow-step-count" data-flow-step-count>—</span>' +
            '</div>' +
            '<div class="linkage-flow-progress" aria-hidden="true"><span data-flow-progress></span></div>' +
            '<div class="linkage-flow-window" data-flow-window></div>' +
            '<div class="linkage-flow-current">' +
                '<span>Paso actual</span>' +
                '<h4 data-flow-title></h4>' +
                '<p data-flow-description></p>' +
                '<div class="linkage-flow-missing d-none" data-flow-missing></div>' +
            '</div>' +
            '<div class="linkage-flow-actions has-single-action" data-flow-actions></div>';
        referencia.insertAdjacentElement('afterend', bloque);
        return bloque;
    };

    const sincronizarBotonVerificacion = function (offcanvas, pasoActual) {
        const boton = offcanvas.querySelector('[data-work-verify-contact]');
        if (!boton || Number(window.IMPE_CURRENT_ROLE_ID || 0) !== 4) {
            return;
        }

        const texto = String(boton.textContent || '').toLowerCase();
        const yaVerificado = texto.includes('información verificada');
        const puedeVerificar = Number(pasoActual) === 4 && !yaVerificado;
        boton.disabled = !puedeVerificar;

        if (yaVerificado) {
            boton.title = 'La información ya fue verificada';
        } else if (!puedeVerificar) {
            boton.title = 'Primero completa la llamada de validación y los datos del contacto.';
        } else {
            boton.title = '';
        }
    };

    const renderizarPanel = function (seguimientoId, flujo, emitirEvento) {
        const offcanvas = document.getElementById('offcanvasSeguimientoTrabajo');
        if (!offcanvas || !flujo) {
            return false;
        }

        const bloque = obtenerOCrearBloque(offcanvas);
        if (!bloque) {
            return false;
        }

        const pasoActual = Number(flujo.paso_actual || 0);
        const totalPasos = Number(flujo.total_pasos || 13);
        const titulo = String(flujo.titulo || 'Próxima acción');
        const esAliado = Boolean(flujo.contexto?.es_aliado);
        const telefonoDisponible = String(flujo.contexto?.telefono_disponible || '').trim();
        let accionPrincipal = flujo.accion_principal;
        let accionSecundaria = flujo.accion_secundaria;

        if (pasoActual === 1 && telefonoDisponible !== '') {
            accionPrincipal = {
                codigo: 'LLAMAR_IP',
                etiqueta: 'Comenzar investigación',
                icono: 'bi-telephone'
            };
            accionSecundaria = {
                codigo: 'REGISTRAR_LLAMADA',
                etiqueta: 'Registrar llamada de prueba',
                icono: 'bi-journal-check'
            };
        }

        bloque.classList.remove('d-none');
        bloque.classList.toggle('is-ally', esAliado);
        offcanvas.dataset.flowStep = String(pasoActual);
        offcanvas.dataset.flowTitle = titulo;
        offcanvas.dataset.flowSeguimientoId = String(seguimientoId);
        offcanvas.dataset.ally = esAliado ? '1' : '0';

        const etiquetaPanel = offcanvas.querySelector('.linkage-work-header > div > span');
        if (etiquetaPanel) {
            etiquetaPanel.textContent = esAliado
                ? 'Expediente de aliado'
                : (Number(window.IMPE_CURRENT_ROLE_ID || 0) === 1
                    ? 'Vista de seguimiento'
                    : 'Panel de trabajo');
        }

        sincronizarBotonVerificacion(offcanvas, pasoActual);

        const contador = bloque.querySelector('[data-flow-step-count]');
        const progreso = bloque.querySelector('[data-flow-progress]');
        const ventana = bloque.querySelector('[data-flow-window]');
        const tituloElemento = bloque.querySelector('[data-flow-title]');
        const descripcion = bloque.querySelector('[data-flow-description]');
        const faltantes = bloque.querySelector('[data-flow-missing]');
        const acciones = bloque.querySelector('[data-flow-actions]');

        if (contador) {
            contador.textContent = 'Paso ' + pasoActual + ' de ' + totalPasos;
        }
        if (progreso) {
            progreso.style.width = Math.max(0, Math.min(100, Number(flujo.porcentaje || 0))) + '%';
        }
        if (ventana) {
            ventana.innerHTML =
                renderizarNodo(flujo.ventana?.anterior, 'anterior') +
                renderizarNodo(flujo.ventana?.actual, 'actual') +
                renderizarNodo(flujo.ventana?.siguiente, 'siguiente');
        }
        if (tituloElemento) {
            tituloElemento.textContent = titulo;
        }
        const etiquetaActual = bloque.querySelector('.linkage-flow-current > span');
        if (etiquetaActual) {
            etiquetaActual.textContent = esAliado ? 'CIERRE DE RUTA' : 'PASO ACTUAL';
        }
        if (descripcion) {
            descripcion.textContent = String(flujo.descripcion || '');
        }

        const listaFaltantes = Array.isArray(flujo.faltantes)
            ? flujo.faltantes.filter(Boolean)
            : [];
        if (faltantes) {
            faltantes.classList.toggle('d-none', listaFaltantes.length === 0);
            faltantes.innerHTML = listaFaltantes.length === 0
                ? ''
                : '<span class="linkage-flow-missing-label">Falta:</span>' +
                    listaFaltantes.map(function (item) {
                        return '<span class="linkage-flow-chip">' + escapar(item) + '</span>';
                    }).join('');
        }

        if (acciones) {
            acciones.innerHTML =
                botonAccion(accionPrincipal, true) +
                botonAccion(accionSecundaria, false);
            acciones.classList.toggle(
                'has-single-action',
                !accionSecundaria || !accionSecundaria.codigo
            );
        }

        const proximaSeccion = offcanvas.querySelector('[data-work-next-section]');
        const proximaEtiqueta = offcanvas.querySelector('[data-work-next-label]');
        const proximaAccion = offcanvas.querySelector('[data-work-next-action]');
        proximaSeccion?.classList.toggle('is-ally', esAliado);
        if (proximaEtiqueta) {
            proximaEtiqueta.textContent = esAliado ? 'CONDICIÓN' : 'PRÓXIMA ACCIÓN';
        }
        if (proximaAccion) {
            proximaAccion.textContent = esAliado
                ? 'Aliado · Convenio formalizado'
                : titulo;
        }

        offcanvas.removeAttribute('data-flow-ui-pending');
        offcanvas.removeAttribute('data-flow-ui-prepared');
        offcanvas.removeAttribute('data-flow-ui-fallback');
        offcanvas.dataset.flowCacheVisible = '1';

        if (emitirEvento !== false) {
            document.dispatchEvent(new CustomEvent('impe:flow-updated', {
                detail: {
                    seguimientoId: seguimientoId,
                    pasoActual: pasoActual,
                    titulo: titulo,
                    esAliado: esAliado
                }
            }));
        }

        return true;
    };

    const extraerUrl = function (input) {
        if (typeof input === 'string') {
            return input;
        }
        if (input && typeof input.url === 'string') {
            return input.url;
        }
        return '';
    };

    const esConsultaRuta = function (url) {
        return String(url || '').includes('controller=seguimientoFlujo') &&
            String(url || '').includes('action=estado');
    };

    window.fetch = async function () {
        const argumentos = Array.from(arguments);
        const url = extraerUrl(argumentos[0]);
        const respuesta = await fetchOriginal.apply(window, argumentos);

        if (esConsultaRuta(url)) {
            respuesta.clone().json().then(function (datos) {
                if (!datos?.ok || !datos?.flujo) {
                    return;
                }

                const seguimientoId = Number(
                    datos.flujo.seguimiento_id ||
                    new URL(url, window.location.href).searchParams.get('seguimiento_id') ||
                    0
                );

                if (seguimientoId <= 0) {
                    return;
                }

                guardar(seguimientoId, datos.flujo);
                aplicarFila(seguimientoId, datos.flujo, true);
            }).catch(function () {
                // La respuesta original continúa disponible para su consumidor normal.
            });
        }

        return respuesta;
    };

    window.IMPE_SEGUIMIENTO_RUTA_CACHE = {
        obtener: obtener,
        edad: edad,
        guardar: guardar,
        invalidar: invalidar,
        aplicarFila: aplicarFila,
        renderizarPanel: renderizarPanel
    };

    document.addEventListener('DOMContentLoaded', function () {
        const filas = Array.from(document.querySelectorAll('[data-linkage-follow-row]'));

        filas.forEach(function (fila) {
            if (fila.dataset.routeInitialReady === '1') {
                return;
            }

            const seguimientoId = Number(
                fila.querySelector('[data-work-follow-id]')?.getAttribute('data-work-follow-id') || 0
            );
            const flujo = obtener(seguimientoId);

            if (flujo) {
                aplicarFila(seguimientoId, flujo, true);
            }
        });

        marcarResumenListo();
    });
})();
