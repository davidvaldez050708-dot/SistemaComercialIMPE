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
        const enlaceLimpiar = document.querySelector('.filter-clear-link');
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
            [12, 'Reunión realizada'],
            [13, 'Convenio']
        ];

        const instalarFiltroRuta = function () {
            if (!formularioFiltros || document.querySelector('[data-linkage-route-filter]')) {
                selectorRuta = document.querySelector('[data-linkage-route-filter]');
                return;
            }

            const selectorEstado = formularioFiltros.querySelector('[data-linkage-stage-filter]');
            const campoEstado = selectorEstado?.closest('.data-filter-field');
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

            if (campoEstado) {
                campoEstado.insertAdjacentElement('afterend', campo);
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
            if (!contadorResultados) {
                return;
            }

            const visibles = filasElementos.filter(filaVisible).length;
            contadorResultados.textContent =
                visibles + (visibles === 1 ? ' resultado' : ' resultados');
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

        const aplicarFiltroRuta = function () {
            const pasoSeleccionado = String(selectorRuta?.value || '');

            filasElementos.forEach(function (fila) {
                const pasoFila = String(fila.dataset.flowStep || '');
                const filtrar = pasoSeleccionado !== '' && pasoFila !== pasoSeleccionado;

                if (filtrar) {
                    fila.dataset.routeFiltered = '1';
                } else {
                    delete fila.dataset.routeFiltered;
                }
            });

            actualizarGrupos();
            actualizarContador();
            actualizarBotonLimpiar();
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
        });

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
                // La bandeja conserva el valor renderizado por PHP si no puede sincronizarse.
            } finally {
                procesarSiguiente();
            }
        }

        document.addEventListener('impe:flow-row-updated', aplicarFiltroRuta);
        window.setTimeout(aplicarFiltroRuta, 80);
    });
})();
