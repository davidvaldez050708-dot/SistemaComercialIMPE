(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        const MARCADORES = {
            BUZON_VOZ: '[BUZON_VOZ]',
            FUERA_SERVICIO: '[FUERA_SERVICIO]'
        };

        const RESULTADOS = {
            CONTACTADO: 'Contactado',
            NO_CONTESTO: 'No contestó',
            OCUPADO: 'Ocupado',
            NUMERO_INCORRECTO: 'Número incorrecto',
            SOLICITO_LLAMAR_DESPUES: 'Solicitó llamar después',
            MENSAJE_ENVIADO: 'Solicitó información',
            CORREO_ENVIADO: 'Correo enviado',
            SIN_RESPUESTA: 'Sin respuesta',
            BUZON_VOZ: 'Buzón de voz',
            FUERA_SERVICIO: 'Fuera de servicio',
            OTRO: 'Otro'
        };

        const normalizar = function (valor) {
            return String(valor || '')
                .normalize('NFD')
                .replace(/[\u0300-\u036f]/g, '')
                .trim()
                .toLowerCase();
        };

        const fechaLegible = function (valor) {
            const cadena = String(valor || '').trim();
            const coincidencia = cadena.match(
                /^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2}))?/
            );

            if (!coincidencia) {
                return cadena || '—';
            }

            const meses = [
                'ene', 'feb', 'mar', 'abr', 'may', 'jun',
                'jul', 'ago', 'sep', 'oct', 'nov', 'dic'
            ];
            let salida =
                coincidencia[3] + ' ' +
                meses[Math.max(0, Number(coincidencia[2]) - 1)] + ' ' +
                coincidencia[1];

            if (coincidencia[4]) {
                salida += ' · ' + coincidencia[4] + ':' + coincidencia[5];
            }

            return salida;
        };

        const duracionLegible = function (segundos) {
            const total = Math.max(0, Number(segundos) || 0);

            if (total <= 0) {
                return '—';
            }

            const minutos = Math.floor(total / 60);
            const resto = total % 60;
            return String(minutos).padStart(2, '0') + ':' + String(resto).padStart(2, '0');
        };

        const etiquetaResultado = function (valor) {
            const clave = String(valor || '').toUpperCase();
            return RESULTADOS[clave] || 'Llamada registrada';
        };

        const limpiarMarcadores = function (valor) {
            return String(valor || '')
                .replaceAll(MARCADORES.BUZON_VOZ, '')
                .replaceAll(MARCADORES.FUERA_SERVICIO, '')
                .replace(/\n{3,}/g, '\n\n')
                .trim();
        };

        const inicializarResultadosTelefonicos = function () {
            const formulario = document.querySelector('[data-work-interaction-form]');

            if (!formulario) {
                return;
            }

            const canal = formulario.querySelector('[name="canal"]');
            const resultado = formulario.querySelector('[name="resultado"]');
            const observacion = formulario.querySelector('[name="observacion"]');
            const proximaAccion = formulario.querySelector('[name="proxima_accion"]');

            if (!canal || !resultado || !observacion) {
                return;
            }

            const opcionesEspeciales = [
                { valor: 'BUZON_VOZ', etiqueta: 'Buzón de voz' },
                { valor: 'FUERA_SERVICIO', etiqueta: 'Fuera de servicio' }
            ];

            const agregarOpciones = function () {
                opcionesEspeciales.forEach(function (item) {
                    if (resultado.querySelector('option[value="' + item.valor + '"]')) {
                        return;
                    }

                    const opcion = document.createElement('option');
                    opcion.value = item.valor;
                    opcion.textContent = item.etiqueta;
                    opcion.setAttribute('data-call-special-result', '');
                    resultado.appendChild(opcion);
                });
            };

            const quitarOpciones = function () {
                if (['BUZON_VOZ', 'FUERA_SERVICIO'].includes(String(resultado.value || ''))) {
                    resultado.value = '';
                    resultado.dispatchEvent(new Event('change', { bubbles: true }));
                }

                resultado.querySelectorAll('[data-call-special-result]').forEach(function (opcion) {
                    opcion.remove();
                });
            };

            const actualizarOpciones = function () {
                if (String(canal.value || '').toUpperCase() === 'LLAMADA') {
                    agregarOpciones();
                } else {
                    quitarOpciones();
                }
            };

            const actualizarResumenTecnico = function () {
                const valor = String(resultado.value || '');

                if (!['BUZON_VOZ', 'FUERA_SERVICIO'].includes(valor)) {
                    return;
                }

                const resumen = formulario.querySelector('[data-twilio-call-summary] .alert');
                if (!resumen) {
                    return;
                }

                const etiqueta = valor === 'BUZON_VOZ'
                    ? 'Buzón de voz'
                    : 'Fuera de servicio';

                resumen.innerHTML =
                    '<i class="bi bi-telephone-check me-1"></i>' +
                    '<strong>Llamada real vinculada.</strong> ' +
                    etiqueta + ' · el intento quedará en el historial y la grabación no se conservará.';
            };

            canal.addEventListener('change', actualizarOpciones);
            resultado.addEventListener('change', function () {
                const valor = String(resultado.value || '');

                if (valor === 'BUZON_VOZ' || valor === 'FUERA_SERVICIO') {
                    window.setTimeout(function () {
                        if (proximaAccion) {
                            proximaAccion.value = valor === 'BUZON_VOZ'
                                ? 'Volver a llamar'
                                : 'Investigar nuevo contacto';
                            proximaAccion.dispatchEvent(new Event('change', { bubbles: true }));
                        }
                        actualizarResumenTecnico();
                    }, 0);
                }
            });

            document.addEventListener('submit', function (event) {
                if (event.target !== formulario) {
                    return;
                }

                const valorEspecial = String(resultado.value || '');
                if (!['BUZON_VOZ', 'FUERA_SERVICIO'].includes(valorEspecial)) {
                    return;
                }

                const marcador = MARCADORES[valorEspecial];
                const observacionOriginal = limpiarMarcadores(observacion.value);
                const resultadoOriginal = valorEspecial;

                observacion.value = [marcador, observacionOriginal]
                    .filter(Boolean)
                    .join('\n');
                resultado.value = 'OTRO';

                window.setTimeout(function () {
                    resultado.value = resultadoOriginal;
                    observacion.value = observacionOriginal;
                }, 0);
            }, true);

            formulario.addEventListener('reset', function () {
                window.setTimeout(actualizarOpciones, 0);
            });

            actualizarOpciones();
        };

        const corregirHistorialGeneral = function () {
            document.querySelectorAll('.linkage-history-item').forEach(function (item) {
                const canal = normalizar(item.querySelector('strong')?.textContent);
                const parrafo = item.querySelector('p');

                if (canal !== 'llamada' || !parrafo) {
                    return;
                }

                const notas = String(parrafo.textContent || '');
                let etiqueta = '';

                if (notas.includes(MARCADORES.BUZON_VOZ)) {
                    etiqueta = 'Buzón de voz';
                } else if (notas.includes(MARCADORES.FUERA_SERVICIO)) {
                    etiqueta = 'Fuera de servicio';
                }

                if (!etiqueta) {
                    return;
                }

                parrafo.textContent = limpiarMarcadores(notas) || 'Intento telefónico registrado.';
                const meta = item.querySelector('div > span');

                if (meta) {
                    const partes = String(meta.textContent || '').split('·');
                    if (partes.length > 1) {
                        partes[partes.length - 1] = ' ' + etiqueta;
                        meta.textContent = partes.join('·').trim();
                    }
                }
            });
        };

        const inicializarExpedienteLlamadas = function () {
            const parametros = new URLSearchParams(window.location.search);
            const esExpediente =
                parametros.get('controller') === 'seguimientoVinculacion' &&
                parametros.get('action') === 'detalle';
            const seguimientoId = Number(parametros.get('id') || 0);

            if (!esExpediente || seguimientoId <= 0) {
                return;
            }

            let intentos = 0;
            const esperarExpediente = function () {
                const nav = document.querySelector('.linkage-expediente-section-nav');
                const contenido = document.querySelector('.linkage-expediente-content');

                if ((!nav || !contenido) && intentos < 10) {
                    intentos++;
                    window.setTimeout(esperarExpediente, 35);
                    return;
                }

                if (!nav || !contenido || nav.querySelector('[data-expediente-tab="llamadas"]')) {
                    return;
                }

                document.querySelectorAll('[data-call-recordings]').forEach(function (bloqueAnterior) {
                    bloqueAnterior.remove();
                });
                corregirHistorialGeneral();

                const tabInteracciones = nav.querySelector('[data-expediente-tab="interacciones"]');
                const paneInteracciones = contenido.querySelector('[data-expediente-pane="interacciones"]');
                const tab = document.createElement('span');
                tab.textContent = 'Llamadas';
                tab.dataset.expedienteTab = 'llamadas';
                tab.id = 'expediente-tab-llamadas';
                tab.setAttribute('role', 'tab');
                tab.setAttribute('aria-controls', 'expediente-panel-llamadas');
                tab.setAttribute('aria-selected', 'false');
                tab.tabIndex = -1;

                if (tabInteracciones) {
                    tabInteracciones.insertAdjacentElement('afterend', tab);
                } else {
                    nav.appendChild(tab);
                }

                const pane = document.createElement('div');
                pane.className = 'linkage-expediente-pane linkage-call-pane';
                pane.dataset.expedientePane = 'llamadas';
                pane.id = 'expediente-panel-llamadas';
                pane.setAttribute('role', 'tabpanel');
                pane.setAttribute('aria-labelledby', tab.id);
                pane.setAttribute('aria-hidden', 'true');
                pane.hidden = true;

                pane.innerHTML =
                    '<section class="dashboard-panel linkage-detail-panel linkage-expediente-section linkage-call-module">' +
                        '<div class="linkage-call-module-heading">' +
                            '<div>' +
                                '<span class="linkage-expediente-eyebrow">REGISTRO TELEFÓNICO</span>' +
                                '<h3>Llamadas</h3>' +
                                '<p>Consulta los intentos telefónicos y escucha únicamente las conversaciones conservadas.</p>' +
                            '</div>' +
                        '</div>' +
                        '<div class="linkage-call-summary" aria-label="Resumen de llamadas">' +
                            '<div><span>Total</span><strong data-call-summary-total>—</strong></div>' +
                            '<div><span>Contactadas</span><strong data-call-summary-contacted>—</strong></div>' +
                            '<div><span>Grabaciones</span><strong data-call-summary-recordings>—</strong></div>' +
                        '</div>' +
                        '<div class="linkage-call-section">' +
                            '<div class="linkage-call-section-heading">' +
                                '<div><strong>Historial de llamadas</strong><p>Todos los intentos realizados, incluso los que no generaron conversación.</p></div>' +
                                '<span class="linkage-call-recordings-count" data-call-count>—</span>' +
                            '</div>' +
                            '<div class="linkage-call-history-list" data-call-history-list>' +
                                '<div class="linkage-call-recordings-loading">' +
                                    '<span class="spinner-border spinner-border-sm" aria-hidden="true"></span>' +
                                    '<span>Cargando llamadas…</span>' +
                                '</div>' +
                            '</div>' +
                        '</div>' +
                        '<div class="linkage-call-section linkage-call-recordings-section">' +
                            '<div class="linkage-call-section-heading">' +
                                '<div><strong>Grabaciones</strong><p>Solo conversaciones válidas; buzón de voz y mensajes de operadora quedan fuera.</p></div>' +
                                '<span class="linkage-call-recordings-count" data-recording-count>—</span>' +
                            '</div>' +
                            '<div class="linkage-call-audio-list" data-call-audio-list>' +
                                '<div class="linkage-call-recordings-loading">' +
                                    '<span class="spinner-border spinner-border-sm" aria-hidden="true"></span>' +
                                    '<span>Preparando grabaciones…</span>' +
                                '</div>' +
                            '</div>' +
                        '</div>' +
                    '</section>';

                if (paneInteracciones) {
                    paneInteracciones.insertAdjacentElement('afterend', pane);
                } else {
                    contenido.appendChild(pane);
                }

                const ocultarLlamadas = function () {
                    pane.hidden = true;
                    pane.setAttribute('aria-hidden', 'true');
                    tab.classList.remove('active');
                    tab.setAttribute('aria-selected', 'false');
                    tab.tabIndex = -1;
                };

                const activarLlamadas = function (actualizarHash, acomodarVista) {
                    nav.querySelectorAll('[data-expediente-tab]').forEach(function (otroTab) {
                        const activo = otroTab === tab;
                        otroTab.classList.toggle('active', activo);
                        otroTab.setAttribute('aria-selected', activo ? 'true' : 'false');
                        otroTab.tabIndex = activo ? 0 : -1;
                    });

                    contenido.querySelectorAll('.linkage-expediente-pane').forEach(function (otroPane) {
                        const activo = otroPane === pane;
                        otroPane.hidden = !activo;
                        otroPane.setAttribute('aria-hidden', activo ? 'false' : 'true');
                    });

                    if (actualizarHash) {
                        history.replaceState(null, '', '#exp-llamadas');
                    }

                    tab.scrollIntoView({ behavior: 'smooth', block: 'nearest', inline: 'nearest' });

                    if (acomodarVista) {
                        const posicion = nav.getBoundingClientRect().top + window.scrollY - 78;
                        window.scrollTo({ top: Math.max(0, posicion), behavior: 'smooth' });
                    }
                };

                nav.addEventListener('click', function (event) {
                    const destino = event.target.closest('[data-expediente-tab]');
                    if (destino && destino !== tab) {
                        ocultarLlamadas();
                    }
                }, true);

                nav.addEventListener('keydown', function (event) {
                    const destino = event.target.closest('[data-expediente-tab]');
                    if (destino && destino !== tab && ['Enter', ' ', 'ArrowLeft', 'ArrowRight'].includes(event.key)) {
                        window.setTimeout(function () {
                            if (!tab.classList.contains('active')) {
                                ocultarLlamadas();
                            }
                        }, 0);
                    }
                }, true);

                tab.addEventListener('click', function () {
                    activarLlamadas(true, true);
                });

                tab.addEventListener('keydown', function (event) {
                    const tabs = Array.from(nav.querySelectorAll('[data-expediente-tab]'));
                    const indice = tabs.indexOf(tab);
                    let destino = null;

                    if (event.key === 'ArrowRight') {
                        destino = tabs[(indice + 1) % tabs.length];
                    } else if (event.key === 'ArrowLeft') {
                        destino = tabs[(indice - 1 + tabs.length) % tabs.length];
                    } else if (event.key === 'Enter' || event.key === ' ') {
                        event.preventDefault();
                        activarLlamadas(true, true);
                        return;
                    }

                    if (destino) {
                        event.preventDefault();
                        if (destino === tab) {
                            activarLlamadas(true, false);
                        } else {
                            ocultarLlamadas();
                            destino.focus();
                            destino.click();
                        }
                    }
                });

                const historyList = pane.querySelector('[data-call-history-list]');
                const audioList = pane.querySelector('[data-call-audio-list]');
                const countCalls = pane.querySelector('[data-call-count]');
                const countRecordings = pane.querySelector('[data-recording-count]');
                const summaryTotal = pane.querySelector('[data-call-summary-total]');
                const summaryContacted = pane.querySelector('[data-call-summary-contacted]');
                const summaryRecordings = pane.querySelector('[data-call-summary-recordings]');

                const crearEstadoVacio = function (mensaje, icono) {
                    const vacio = document.createElement('div');
                    vacio.className = 'linkage-call-recordings-empty';
                    vacio.innerHTML = '<i class="bi ' + icono + '"></i><span></span>';
                    vacio.querySelector('span').textContent = mensaje;
                    return vacio;
                };

                const crearTarjetaHistorial = function (llamada) {
                    const tarjeta = document.createElement('article');
                    tarjeta.className = 'linkage-call-history-card';

                    const icono = document.createElement('span');
                    icono.className = 'linkage-call-recording-card-icon';
                    icono.innerHTML = '<i class="bi bi-telephone-outbound"></i>';

                    const cuerpo = document.createElement('div');
                    cuerpo.className = 'linkage-call-recording-body';
                    const cabecera = document.createElement('div');
                    cabecera.className = 'linkage-call-recording-top';

                    const identidad = document.createElement('div');
                    const titulo = document.createElement('strong');
                    titulo.textContent = etiquetaResultado(llamada.resultado_telefonico || llamada.resultado);
                    const meta = document.createElement('span');
                    const partes = [fechaLegible(llamada.fecha_inicio)];
                    const usuario = String(llamada.usuario || '').trim();
                    if (usuario) {
                        partes.push(usuario);
                    }
                    meta.textContent = partes.join(' · ');
                    identidad.appendChild(titulo);
                    identidad.appendChild(meta);

                    const derecha = document.createElement('div');
                    derecha.className = 'linkage-call-history-meta';
                    const duracion = document.createElement('span');
                    duracion.className = 'linkage-call-recording-duration';
                    duracion.innerHTML = '<i class="bi bi-clock"></i><span></span>';
                    duracion.querySelector('span').textContent = duracionLegible(llamada.duracion_segundos);
                    derecha.appendChild(duracion);

                    const estadoAudio = document.createElement('span');
                    estadoAudio.className = llamada.tiene_grabacion
                        ? 'linkage-call-audio-state is-available'
                        : 'linkage-call-audio-state';
                    estadoAudio.innerHTML = llamada.tiene_grabacion
                        ? '<i class="bi bi-record-circle"></i><span>Grabación</span>'
                        : (llamada.excluir_grabacion
                            ? '<i class="bi bi-mic-mute"></i><span>Solo historial</span>'
                            : '<i class="bi bi-mic-mute"></i><span>Sin grabación</span>');
                    derecha.appendChild(estadoAudio);

                    cabecera.appendChild(identidad);
                    cabecera.appendChild(derecha);
                    cuerpo.appendChild(cabecera);

                    const notas = limpiarMarcadores(llamada.notas);
                    if (notas) {
                        const p = document.createElement('p');
                        p.className = 'linkage-call-recording-notes';
                        p.textContent = notas;
                        cuerpo.appendChild(p);
                    }

                    tarjeta.appendChild(icono);
                    tarjeta.appendChild(cuerpo);
                    return tarjeta;
                };

                const crearTarjetaAudio = function (llamada) {
                    const tarjeta = document.createElement('article');
                    tarjeta.className = 'linkage-call-audio-card';

                    const cabecera = document.createElement('div');
                    cabecera.className = 'linkage-call-audio-card-heading';
                    const identidad = document.createElement('div');
                    identidad.innerHTML =
                        '<span class="linkage-call-recordings-icon"><i class="bi bi-play-circle"></i></span>' +
                        '<div><strong></strong><span></span></div>';
                    identidad.querySelector('strong').textContent =
                        fechaLegible(llamada.fecha_inicio) + ' · ' +
                        etiquetaResultado(llamada.resultado_telefonico || llamada.resultado);
                    const meta = [String(llamada.usuario || '').trim(), duracionLegible(llamada.duracion_segundos)]
                        .filter(Boolean)
                        .join(' · ');
                    identidad.querySelector('div > span').textContent = meta || 'Conversación registrada';

                    cabecera.appendChild(identidad);
                    tarjeta.appendChild(cabecera);

                    const audioWrap = document.createElement('div');
                    audioWrap.className = 'linkage-call-recording-audio';
                    const etiqueta = document.createElement('span');
                    etiqueta.className = 'linkage-call-recording-audio-label';
                    etiqueta.innerHTML = '<i class="bi bi-record-circle"></i><span>Grabación</span>';
                    const audio = document.createElement('audio');
                    audio.controls = true;
                    audio.preload = 'none';
                    audio.controlsList = 'nodownload';
                    audio.src = String(llamada.grabacion_url || '');
                    audio.setAttribute('aria-label', 'Grabación de llamada del ' + fechaLegible(llamada.fecha_inicio));
                    const error = document.createElement('span');
                    error.className = 'linkage-call-recording-unavailable d-none';
                    error.innerHTML = '<i class="bi bi-exclamation-circle"></i><span>Grabación no disponible.</span>';
                    audio.addEventListener('error', function () {
                        audio.classList.add('d-none');
                        error.classList.remove('d-none');
                    });

                    audioWrap.appendChild(etiqueta);
                    audioWrap.appendChild(audio);
                    audioWrap.appendChild(error);
                    tarjeta.appendChild(audioWrap);
                    return tarjeta;
                };

                const cargar = function () {
                    fetch(
                        'prueba_telefonia/api/llamadas_seguimiento.php?seguimiento_id=' +
                            encodeURIComponent(seguimientoId),
                        {
                            headers: { 'X-Requested-With': 'fetch' },
                            credentials: 'same-origin',
                            cache: 'no-store'
                        }
                    )
                        .then(function (response) {
                            return response.json().then(function (data) {
                                if (!response.ok || !data.ok) {
                                    throw new Error(data.mensaje || 'No fue posible cargar las llamadas.');
                                }
                                return data;
                            });
                        })
                        .then(function (data) {
                            const llamadas = Array.isArray(data.llamadas) ? data.llamadas : [];
                            const grabaciones = llamadas.filter(function (llamada) {
                                return Boolean(llamada.tiene_grabacion && llamada.grabacion_url);
                            });
                            const contactadas = llamadas.filter(function (llamada) {
                                return ['CONTACTADO', 'SOLICITO_LLAMAR_DESPUES', 'MENSAJE_ENVIADO']
                                    .includes(String(llamada.resultado || '').toUpperCase());
                            }).length;

                            countCalls.textContent = String(llamadas.length);
                            countRecordings.textContent = String(grabaciones.length);
                            summaryTotal.textContent = String(llamadas.length);
                            summaryContacted.textContent = String(contactadas);
                            summaryRecordings.textContent = String(grabaciones.length);

                            historyList.innerHTML = '';
                            audioList.innerHTML = '';

                            if (llamadas.length === 0) {
                                historyList.appendChild(crearEstadoVacio(
                                    'Todavía no hay llamadas registradas en este seguimiento.',
                                    'bi-telephone-x'
                                ));
                            } else {
                                llamadas.forEach(function (llamada) {
                                    historyList.appendChild(crearTarjetaHistorial(llamada));
                                });
                            }

                            if (grabaciones.length === 0) {
                                audioList.appendChild(crearEstadoVacio(
                                    'No hay conversaciones grabadas disponibles.',
                                    'bi-mic-mute'
                                ));
                            } else {
                                grabaciones.forEach(function (llamada) {
                                    audioList.appendChild(crearTarjetaAudio(llamada));
                                });
                            }
                        })
                        .catch(function (error) {
                            countCalls.textContent = '—';
                            countRecordings.textContent = '—';
                            summaryTotal.textContent = '—';
                            summaryContacted.textContent = '—';
                            summaryRecordings.textContent = '—';
                            historyList.innerHTML = '';
                            audioList.innerHTML = '';
                            historyList.appendChild(crearEstadoVacio(
                                error.message || 'No fue posible cargar las llamadas.',
                                'bi-exclamation-circle'
                            ));
                            audioList.appendChild(crearEstadoVacio(
                                'Las grabaciones no pudieron consultarse.',
                                'bi-exclamation-circle'
                            ));
                        });
                };

                cargar();

                if (window.location.hash === '#exp-llamadas') {
                    activarLlamadas(false, false);
                }
            };

            esperarExpediente();
        };

        inicializarResultadosTelefonicos();
        window.setTimeout(inicializarExpedienteLlamadas, 0);
    });
})();
