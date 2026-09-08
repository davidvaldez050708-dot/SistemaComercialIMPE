(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        const tabla = document.querySelector('[data-linkage-table-wrapper]');
        if (!tabla) {
            return;
        }

        const observadores = new WeakMap();
        const formularioFiltros = document.querySelector('[data-linkage-state-filters]');
        const filasElementos = Array.from(
            tabla.querySelectorAll('[data-linkage-follow-row]')
        );
        const grupos = Array.from(
            document.querySelectorAll('[data-linkage-municipality-group]')
        );
        const contadorResultados = document.querySelector('[data-linkage-results-count]');
        const enlaceLimpiar = document.querySelector('[data-linkage-clear-filters]');
        const emptyReal = document.querySelector('[data-linkage-empty-real]');
        const emptyFiltrado = document.querySelector('[data-linkage-empty-filtered]');
        const selectorSituacion = formularioFiltros?.querySelector('[data-linkage-stage-filter]') || null;
        let selectorRuta = null;

        const estilosRuta = document.createElement('style');
        estilosRuta.textContent =
            '[data-linkage-follow-row][data-route-filtered="1"]{' +
                'display:none!important;' +
            '}' +
            '[data-linkage-municipality-group][data-route-filtered="1"]{' +
                'display:none!important;' +
            '}';
        document.head.appendChild(estilosRuta);

        const pasosRuta = [
            [1, 'Seguimiento iniciado'],
            [2, 'Investigación de datos'],
            [3, 'Contacto y validación'],
            [4, 'Datos verificados'],
            [5, 'Oficio preparado'],
            [6, 'PDF generado'],
            [7, 'Oficio / correo enviado'],
            [8, 'Esperando respuesta'],
            [9, 'Respuesta recibida'],
            [10, 'Seguimiento por correo'],
            [11, 'Reunión agendada'],
            [12, 'Reunión y acuerdos'],
            [13, 'Convenio']
        ];

        const situacionDesdeEstado = function (estado) {
            const valor = String(estado || '').trim().toUpperCase();

            if (valor === 'DESCARTADO') {
                return 'DESCARTADO';
            }

            if (valor === 'NO_LOCALIZADO') {
                return 'NO_LOCALIZADO';
            }

            return 'EN_PROCESO';
        };

        const normalizarSituacionFila = function (fila) {
            if (!fila) {
                return;
            }

            const valorActual = String(fila.dataset.stage || '').trim().toUpperCase();
            const valoresSituacion = ['EN_PROCESO', 'NO_LOCALIZADO', 'DESCARTADO'];

            if (valoresSituacion.includes(valorActual)) {
                if (!fila.dataset.internalStage) {
                    fila.dataset.internalStage = valorActual;
                }
                return;
            }

            if (valorActual !== '') {
                fila.dataset.internalStage = valorActual;
            }

            const situacion = situacionDesdeEstado(
                fila.dataset.internalStage || valorActual
            );

            if (fila.dataset.stage !== situacion) {
                fila.dataset.stage = situacion;
            }
        };

        const configurarFiltroSituacion = function () {
            filasElementos.forEach(normalizarSituacionFila);

            if (!selectorSituacion) {
                return;
            }

            const campo = selectorSituacion.closest('.data-filter-field');
            const etiqueta = campo?.querySelector('label');
            const parametros = new URLSearchParams(window.location.search);
            const situacionUrl = String(parametros.get('situacion') || '').toUpperCase();
            const estadoAnterior = String(parametros.get('estado_seguimiento') || '').toUpperCase();
            let seleccion = '';

            if (['EN_PROCESO', 'NO_LOCALIZADO', 'DESCARTADO'].includes(situacionUrl)) {
                seleccion = situacionUrl;
            } else if (estadoAnterior === 'NO_LOCALIZADO' || estadoAnterior === 'DESCARTADO') {
                seleccion = estadoAnterior;
            } else if (estadoAnterior !== '') {
                seleccion = 'EN_PROCESO';
            }

            if (etiqueta) {
                etiqueta.textContent = 'Situación';
                etiqueta.setAttribute('for', selectorSituacion.id || 'estado_seguimiento_filtro');
            }

            selectorSituacion.name = 'situacion';
            selectorSituacion.setAttribute('aria-label', 'Filtrar por situación del seguimiento');
            selectorSituacion.innerHTML =
                '<option value="">Todos</option>' +
                '<option value="EN_PROCESO">En proceso</option>' +
                '<option value="NO_LOCALIZADO">No localizado</option>' +
                '<option value="DESCARTADO">Descartado</option>';
            selectorSituacion.value = seleccion;

            const url = new URL(window.location.href);
            url.searchParams.delete('estado_seguimiento');
            if (seleccion) {
                url.searchParams.set('situacion', seleccion);
            } else {
                url.searchParams.delete('situacion');
            }
            window.history.replaceState({}, '', url.toString());

            selectorSituacion.addEventListener('change', function () {
                const nuevaUrl = new URL(window.location.href);
                nuevaUrl.searchParams.delete('estado_seguimiento');

                if (selectorSituacion.value) {
                    nuevaUrl.searchParams.set('situacion', selectorSituacion.value);
                } else {
                    nuevaUrl.searchParams.delete('situacion');
                }

                window.history.replaceState({}, '', nuevaUrl.toString());
            });

            if (window.MutationObserver) {
                filasElementos.forEach(function (fila) {
                    const observadorSituacion = new MutationObserver(function (mutaciones) {
                        const cambioEtapa = mutaciones.some(function (mutacion) {
                            return mutacion.type === 'attributes' &&
                                mutacion.attributeName === 'data-stage';
                        });

                        if (!cambioEtapa) {
                            return;
                        }

                        const valor = String(fila.dataset.stage || '').trim().toUpperCase();
                        if (!['EN_PROCESO', 'NO_LOCALIZADO', 'DESCARTADO'].includes(valor)) {
                            normalizarSituacionFila(fila);
                        }
                    });

                    observadorSituacion.observe(fila, {
                        attributes: true,
                        attributeFilter: ['data-stage']
                    });
                });
            }
        };

        configurarFiltroSituacion();

        const instalarFiltroRuta = function () {
            if (!formularioFiltros || document.querySelector('[data-linkage-route-filter]')) {
                selectorRuta = document.querySelector('[data-linkage-route-filter]');
                return;
            }

            const campoSituacion = selectorSituacion?.closest('.data-filter-field');
            const campo = document.createElement('div');
            campo.className = 'data-filter-field';
            campo.innerHTML =
                '<label for="ruta_vinculacion_filtro">Etapa de ruta</label>' +
                '<select ' +
                    'class="form-select" ' +
                    'id="ruta_vinculacion_filtro" ' +
                    'name="ruta_paso" ' +
                    'aria-label="Filtrar por etapa de la ruta de vinculación" ' +
                    'data-linkage-route-filter>' +
                    '<option value="">Todos los pasos</option>' +
                    pasosRuta.map(function (paso) {
                        return '<option value="' + paso[0] + '">' +
                            'Paso ' + paso[0] + ' · ' + paso[1] +
                        '</option>';
                    }).join('') +
                '</select>';

            if (campoSituacion) {
                campoSituacion.insertAdjacentElement('afterend', campo);
            } else {
                formularioFiltros.appendChild(campo);
            }

            selectorRuta = campo.querySelector('[data-linkage-route-filter]');

            const parametroRuta = new URLSearchParams(window.location.search).get('ruta_paso');
            if (/^(?:[1-9]|1[0-3])$/.test(String(parametroRuta || ''))) {
                selectorRuta.value = String(parametroRuta);
            }
        };

        instalarFiltroRuta();

        const hayOtrosFiltros = function () {
            if (!formularioFiltros) {
                return false;
            }

            return Array.from(formularioFiltros.elements).some(function (campo) {
                if (!(campo instanceof HTMLInputElement || campo instanceof HTMLSelectElement)) {
                    return false;
                }

                if (
                    campo === selectorRuta ||
                    ['controller', 'action', 'estado_id'].includes(String(campo.name || ''))
                ) {
                    return false;
                }

                return String(campo.value || '').trim() !== '';
            });
        };

        const filaVisible = function (fila) {
            return !fila.classList.contains('d-none') &&
                fila.dataset.routeFiltered !== '1';
        };

        const actualizarContador = function () {
            const visibles = filasElementos.filter(filaVisible).length;

            if (contadorResultados) {
                contadorResultados.textContent =
                    visibles + (visibles === 1 ? ' resultado' : ' resultados');
            }

            return visibles;
        };

        const actualizarGrupos = function () {
            grupos.forEach(function (grupo) {
                const filasGrupo = Array.from(
                    grupo.querySelectorAll('[data-linkage-follow-row]')
                );
                const visiblesGrupo = filasGrupo.filter(filaVisible).length;
                const contadorGrupo = grupo.querySelector('[data-linkage-municipality-count]');

                if (contadorGrupo) {
                    contadorGrupo.textContent =
                        visiblesGrupo +
                        (visiblesGrupo === 1 ? ' seguimiento' : ' seguimientos');
                }

                if (visiblesGrupo > 0) {
                    delete grupo.dataset.routeFiltered;
                } else {
                    grupo.dataset.routeFiltered = '1';
                }
            });
        };

        const actualizarBotonLimpiar = function () {
            if (!enlaceLimpiar) {
                return;
            }

            const rutaActiva = String(selectorRuta?.value || '') !== '';
            enlaceLimpiar.classList.toggle(
                'd-none',
                !rutaActiva && !hayOtrosFiltros()
            );
        };

        const actualizarEstadoVacio = function (visibles) {
            const rutaActiva = String(selectorRuta?.value || '') !== '';

            if (!rutaActiva) {
                return;
            }

            tabla.classList.toggle('d-none', visibles === 0);
            emptyReal?.classList.add('d-none');
            emptyFiltrado?.classList.toggle('d-none', visibles !== 0);
        };

        const aplicarFiltroRuta = function () {
            const pasoSeleccionado = String(selectorRuta?.value || '');

            filasElementos.forEach(function (fila) {
                normalizarSituacionFila(fila);
                const pasoFila = String(fila.dataset.flowStep || '');
                const filtrar = pasoSeleccionado !== '' && pasoFila !== pasoSeleccionado;

                if (filtrar) {
                    fila.dataset.routeFiltered = '1';
                } else {
                    delete fila.dataset.routeFiltered;
                }
            });

            actualizarGrupos();
            const visibles = actualizarContador();
            actualizarBotonLimpiar();
            actualizarEstadoVacio(visibles);
        };

        selectorRuta?.addEventListener('change', function () {
            aplicarFiltroRuta();

            const url = new URL(window.location.href);
            if (selectorRuta.value) {
                url.searchParams.set('ruta_paso', selectorRuta.value);
            } else {
                url.searchParams.delete('ruta_paso');
            }
            window.history.replaceState({}, '', url.toString());

            if (!selectorRuta.value) {
                window.setTimeout(function () {
                    const visibles = filasElementos.filter(filaVisible).length;
                    tabla.classList.toggle('d-none', visibles === 0);
                    emptyFiltrado?.classList.toggle(
                        'd-none',
                        !(hayOtrosFiltros() && visibles === 0)
                    );
                }, 0);
            }
        });

        enlaceLimpiar?.addEventListener('click', function () {
            if (selectorSituacion) {
                selectorSituacion.value = '';
            }

            const url = new URL(window.location.href);
            url.searchParams.delete('situacion');
            url.searchParams.delete('estado_seguimiento');

            if (selectorRuta && selectorRuta.value !== '') {
                selectorRuta.value = '';
                url.searchParams.delete('ruta_paso');
            }

            window.history.replaceState({}, '', url.toString());
            window.setTimeout(aplicarFiltroRuta, 0);
        }, true);

        ['input', 'change'].forEach(function (tipoEvento) {
            formularioFiltros?.addEventListener(tipoEvento, function () {
                window.setTimeout(aplicarFiltroRuta, 0);
            });
        });

        document.addEventListener('impe:flow-updated', function () {
            aplicarFiltroRuta();
        });

        const fijarAccionAutoritativa = function (celda, titulo) {
            if (!celda) {
                return;
            }

            const texto = String(titulo || '').trim();
            if (texto === '') {
                return;
            }

            celda.dataset.flowNextAction = texto;

            if (String(celda.textContent || '').trim() !== texto) {
                celda.textContent = texto;
            }

            if (observadores.has(celda) || !window.MutationObserver) {
                return;
            }

            const observador = new MutationObserver(function () {
                const autoritativa = String(celda.dataset.flowNextAction || '').trim();
                const actual = String(celda.textContent || '').trim();

                if (autoritativa === '' || actual === autoritativa) {
                    return;
                }

                observador.disconnect();
                celda.textContent = autoritativa;
                observador.observe(celda, {
                    childList: true,
                    characterData: true,
                    subtree: true
                });
            });

            observador.observe(celda, {
                childList: true,
                characterData: true,
                subtree: true
            });
            observadores.set(celda, observador);
        };

        const etiquetaEtapaRuta = function (pasoActual, tituloFlujo, fila) {
            const estadoInterno = String(fila?.dataset.internalStage || '').toUpperCase();
            const titulo = String(tituloFlujo || '').toLowerCase();

            if (estadoInterno === 'DESCARTADO') {
                return 'Descartado';
            }

            if (pasoActual === 12) {
                if (titulo.includes('programad')) {
                    return 'Reunión programada';
                }

                if (
                    titulo.includes('seguimiento de acuerdos') ||
                    titulo.includes('dar seguimiento')
                ) {
                    return 'Seguimiento de acuerdos';
                }

                return 'Reunión';
            }

            const paso = pasosRuta.find(function (item) {
                return Number(item[0]) === Number(pasoActual);
            });

            return paso ? paso[1] : 'En seguimiento';
        };

        const fijarEtapaRuta = function (fila, pasoActual, tituloFlujo) {
            const celda = fila?.querySelector('[data-row-stage-label]');

            if (!celda || !pasoActual) {
                return;
            }

            const etiqueta = etiquetaEtapaRuta(pasoActual, tituloFlujo, fila);
            fila.dataset.flowStageLabel = etiqueta;
            celda.textContent = etiqueta;
            celda.title = 'Paso ' + pasoActual + ' de 13';
        };

        const filas = filasElementos.map(function (fila) {
            const boton = fila.querySelector('[data-work-follow-id]');
            const seguimientoId = Number(
                boton?.getAttribute('data-work-follow-id') || 0
            );

            return {
                fila: fila,
                seguimientoId: seguimientoId
            };
        }).filter(function (item) {
            return item.seguimientoId > 0;
        });

        if (filas.length === 0) {
            aplicarFiltroRuta();
            return;
        }

        let indice = 0;
        const trabajadores = Math.min(4, filas.length);

        for (let i = 0; i < trabajadores; i++) {
            procesarSiguiente();
        }

        async function procesarSiguiente() {
            const posicion = indice++;
            if (posicion >= filas.length) {
                aplicarFiltroRuta();
                return;
            }

            const item = filas[posicion];

            try {
                const respuesta = await fetch(
                    'index.php?controller=seguimientoFlujo&action=estado&seguimiento_id=' +
                    encodeURIComponent(item.seguimientoId),
                    {
                        headers: {
                            'X-Requested-With': 'fetch'
                        },
                        cache: 'no-store'
                    }
                );

                if (!respuesta.ok) {
                    return;
                }

                const datos = await respuesta.json();
                const titulo = String(datos?.flujo?.titulo || '').trim();
                const pasoActual = Number(datos?.flujo?.paso_actual || 0);
                const celda = item.fila.querySelector('[data-row-next-action]');

                if (datos?.ok && datos?.flujo) {
                    if (pasoActual > 0) {
                        item.fila.dataset.flowStep = String(pasoActual);
                        fijarEtapaRuta(item.fila, pasoActual, titulo);
                    }

                    if (titulo !== '') {
                        item.fila.dataset.flowTitle = titulo;
                    }

                    if (titulo !== '' && celda) {
                        fijarAccionAutoritativa(celda, titulo);
                    }

                    document.dispatchEvent(new CustomEvent('impe:flow-row-updated', {
                        detail: {
                            seguimientoId: item.seguimientoId,
                            pasoActual: pasoActual,
                            titulo: titulo
                        }
                    }));
                    aplicarFiltroRuta();
                }
            } catch (error) {
                // Si no puede sincronizarse, la bandeja conserva los datos renderizados por PHP.
            } finally {
                procesarSiguiente();
            }
        }

        document.addEventListener('impe:flow-row-updated', aplicarFiltroRuta);
        window.setTimeout(function () {
            selectorSituacion?.dispatchEvent(new Event('change', { bubbles: true }));
            aplicarFiltroRuta();
        }, 80);
    });
})();
