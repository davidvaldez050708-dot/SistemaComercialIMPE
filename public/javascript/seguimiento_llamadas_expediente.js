(function () {
    'use strict';

    // Asegura que la paginación del expediente cargue sus estilos incluso
    // si la plantilla del detalle omite la hoja correspondiente.
    (function cargarEstilosPaginacion() {
        const script = document.currentScript;
        const urlScript = script && script.src ? script.src : '';
        if (!urlScript) return;
        let cssUrl;
        try {
            cssUrl = new URL(urlScript, document.baseURI);
            if (!/\/javascript\/seguimiento_llamadas_expediente\.js$/.test(cssUrl.pathname)) return;
            cssUrl.pathname = cssUrl.pathname.replace(
                '/javascript/seguimiento_llamadas_expediente.js',
                '/css/seguimiento_llamadas_expediente.css'
            );
            cssUrl.search = '?v=20261016-paginacion-estilos-v2';
        } catch (error) { return; }
        const existente = Array.from(document.querySelectorAll('link[rel="stylesheet"]'))
            .some(function (link) { return link.href === cssUrl.href; });
        if (existente) return;
        const link = document.createElement('link');
        link.rel = 'stylesheet';
        link.href = cssUrl.href;
        link.dataset.seguimientoPaginationCss = '1';
        document.head.appendChild(link);
    })();

    document.addEventListener('DOMContentLoaded', function () {
        const MARCADORES = {
            BUZON_VOZ: '[BUZON_VOZ]',
            FUERA_SERVICIO: '[FUERA_SERVICIO]'
        };

        const RESULTADOS = {
            CONTACTADO: 'Contacto correcto',
            NO_CONTESTO: 'No contestó',
            OCUPADO: 'Ocupado',
            SIN_RESPUESTA: 'Sin respuesta',
            BUZON_VOZ: 'Buzón de voz',
            FUERA_SERVICIO: 'Fuera del área / fuera de servicio',
            NUMERO_INCORRECTO: 'Número incorrecto',
            CONTACTO_INCORRECTO: 'Contacto incorrecto',
            SOLICITO_INFORMACION: 'Solicitó información',
            SOLICITO_LLAMAR_DESPUES: 'Solicitó volver a llamar',
            MENSAJE_ENVIADO: 'Mensaje enviado',
            CORREO_ENVIADO: 'Correo enviado',
            NO_INTERESADO: 'No interesado',
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
                { valor: 'FUERA_SERVICIO', etiqueta: 'Fuera del área / fuera de servicio' }
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

                const resumen = formulario.querySelector('[data-telephony-call-summary] .impe-call-linked-note__body');
                if (!resumen) {
                    return;
                }

                const etiqueta = valor === 'BUZON_VOZ'
                    ? 'Buzón de voz'
                    : 'Fuera del área / fuera de servicio';

                resumen.innerHTML =
                    '<strong>Llamada registrada</strong>' +
                    '<span>' + etiqueta + ' · intento sin conversación grabable.</span>';
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
                    etiqueta = 'Fuera del área / fuera de servicio';
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
                            '<nav class="linkage-call-pagination" data-call-pagination aria-label="Páginas del historial de llamadas" hidden></nav>' +
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
                const pagination = pane.querySelector('[data-call-pagination]');
                const pageSize = 8;
                let currentPage = 1;
                let allCalls = [];

                const renderHistory = function () {
                    const totalPages = Math.max(1, Math.ceil(allCalls.length / pageSize));
                    currentPage = Math.min(Math.max(1, currentPage), totalPages);
                    historyList.replaceChildren();
                    pagination.replaceChildren();
                    if (allCalls.length === 0) {
                        historyList.appendChild(crearEstadoVacio(
                            'Todavía no hay llamadas registradas en este seguimiento.',
                            'bi-telephone-x'
                        ));
                        pagination.hidden = true;
                        return;
                    }
                    allCalls.slice((currentPage - 1) * pageSize, currentPage * pageSize)
                        .forEach(function (call) {
                            historyList.appendChild(crearTarjetaHistorial(call));
                        });
                    pagination.hidden = totalPages <= 1;
                    if (totalPages <= 1) return;

                    const info = document.createElement('span');
                    info.className = 'linkage-call-pagination-info';
                    info.textContent = 'Mostrando ' +
                        ((currentPage - 1) * pageSize + 1) + '–' +
                        Math.min(currentPage * pageSize, allCalls.length) +
                        ' de ' + allCalls.length + ' llamadas';
                    pagination.appendChild(info);
                    const actions = document.createElement('div');
                    actions.className = 'linkage-call-pagination-actions';
                    pagination.appendChild(actions);
                    const makeButton = function (label, page, isCurrent, disabled, icon) {
                        const button = document.createElement('button');
                        button.type = 'button';
                        button.className = 'linkage-call-page-button' +
                            (isCurrent ? ' is-active' : '');
                        button.disabled = disabled;
                        button.textContent = label;
                        button.setAttribute('aria-label', icon ? label + ' página' : 'Página ' + label);
                        if (isCurrent) button.setAttribute('aria-current', 'page');
                        button.addEventListener('click', function () {
                            if (page === currentPage || disabled) return;
                            // Evitar que un audio de una página anterior siga sonando.
                            historyList.querySelectorAll('audio').forEach(function (audio) {
                                audio.pause();
                            });
                            currentPage = page;
                            renderHistory();
                            // Mantener el encabezado de la sección visible al paginar.
                            pane.querySelector('.linkage-call-section-heading')?.scrollIntoView({
                                behavior: 'auto', block: 'nearest'
                            });
                        });
                        actions.appendChild(button);
                    };
                    makeButton('Anterior', currentPage - 1, false, currentPage === 1, true);
                    const pages = new Set([1, totalPages]);
                    for (let p = Math.max(1, currentPage - 2); p <= Math.min(totalPages, currentPage + 2); p++) pages.add(p);
                    let previous = 0;
                    Array.from(pages).sort(function (a,b) { return a-b; }).forEach(function (page) {
                        if (previous && page - previous > 1) {
                            const dots = document.createElement('span');
                            dots.className = 'linkage-call-page-dots';
                            dots.textContent = '…';
                            dots.setAttribute('aria-hidden', 'true');
                            actions.appendChild(dots);
                        }
                        makeButton(String(page), page, page === currentPage, false, false);
                        previous = page;
                    });
                    makeButton('Siguiente', currentPage + 1, false, currentPage === totalPages, true);
                };

                const countCalls = pane.querySelector('[data-call-count]');
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

                const crearFilaDatoLlamada = function (etiqueta, valor) {
                    const texto = String(valor || '').trim();
                    if (!texto) {
                        return null;
                    }

                    const fila = document.createElement('div');
                    fila.className = 'linkage-call-detail-row';

                    const label = document.createElement('span');
                    label.className = 'linkage-call-detail-label';
                    label.textContent = etiqueta;

                    const contenido = document.createElement('span');
                    contenido.className = 'linkage-call-detail-value';
                    contenido.textContent = texto;

                    fila.appendChild(label);
                    fila.appendChild(contenido);
                    return fila;
                };

                const crearBadgeResultado = function (texto, clase) {
                    const badge = document.createElement('span');
                    badge.className = 'linkage-call-result-badge' + (clase ? ' ' + clase : '');
                    badge.textContent = texto;
                    return badge;
                };

                const crearTarjetaHistorial = function (llamada) {
                    const tarjeta = document.createElement('article');
                    tarjeta.className = 'linkage-call-history-card';
                    tarjeta.dataset.callInteractionId = String(llamada.id || '');

                    const icono = document.createElement('span');
                    icono.className = 'linkage-call-recording-card-icon';
                    icono.innerHTML = '<i class="bi bi-telephone-outbound"></i>';

                    const cuerpo = document.createElement('div');
                    cuerpo.className = 'linkage-call-recording-body';

                    const cabecera = document.createElement('div');
                    cabecera.className = 'linkage-call-recording-top';

                    const identidad = document.createElement('div');
                    identidad.className = 'linkage-call-history-identity';

                    const titulo = document.createElement('strong');
                    titulo.textContent = 'Llamada';

                    const fecha = document.createElement('span');
                    fecha.className = 'linkage-call-history-date';
                    fecha.textContent = fechaLegible(llamada.fecha_inicio);

                    identidad.appendChild(titulo);
                    cabecera.appendChild(identidad);
                    cabecera.appendChild(fecha);
                    cuerpo.appendChild(cabecera);

                    const detalle = document.createElement('div');
                    detalle.className = 'linkage-call-detail';

                    const filaContacto = crearFilaDatoLlamada(
                        'Contacto',
                        llamada.contacto
                    );
                    if (filaContacto) {
                        detalle.appendChild(filaContacto);
                    }

                    const detalleTexto = String(llamada.detalle || '').trim();
                    const notasCompatibles = detalleTexto ||
                        (!llamada.contacto ? limpiarMarcadores(llamada.notas) : '');
                    const filaDetalle = crearFilaDatoLlamada(
                        'Detalle',
                        notasCompatibles
                    );
                    if (filaDetalle) {
                        detalle.appendChild(filaDetalle);
                    }

                    if (detalle.children.length > 0) {
                        cuerpo.appendChild(detalle);
                    }

                    const pie = document.createElement('div');
                    pie.className = 'linkage-call-history-footer';

                    const resultados = document.createElement('div');
                    resultados.className = 'linkage-call-result-group';
                    resultados.appendChild(
                        crearBadgeResultado(
                            etiquetaResultado(llamada.resultado_telefonico || llamada.resultado),
                            'is-result'
                        )
                    );

                    if (llamada.contacto_efectivo === true) {
                        resultados.appendChild(
                            crearBadgeResultado(
                                String(llamada.proveedor || '').toUpperCase() === 'ZADARMA_WEBRTC'
                                    ? 'Contacto reportado' : 'Contacto efectivo',
                                'is-contact'
                            )
                        );
                    } else if (
                        ['NO_CONTESTO', 'SIN_RESPUESTA'].includes(
                            String(llamada.resultado || '').toUpperCase()
                        )
                    ) {
                        resultados.appendChild(
                            crearBadgeResultado('Sin contacto', 'is-no-contact')
                        );
                    }

                    if (llamada.verificacion_efectiva === true) {
                        const evidencias = Array.isArray(llamada.verificacion_evidencias)
                            ? llamada.verificacion_evidencias.filter(Boolean)
                            : [];
                        const badgeVerificacion = crearBadgeResultado(
                            'Verificación efectiva',
                            'is-verification'
                        );
                        if (evidencias.length > 0) {
                            badgeVerificacion.title = evidencias.join(' · ');
                        }
                        resultados.appendChild(badgeVerificacion);
                    } else if (llamada.registro_prueba === true) {
                        resultados.appendChild(
                            crearBadgeResultado(
                                'Registro de prueba · no contabiliza',
                                'is-verification-test'
                            )
                        );
                    } else if (llamada.registro_manual === true) {
                        resultados.appendChild(
                            crearBadgeResultado(
                                'Registro manual · no contabiliza',
                                'is-verification-test'
                            )
                        );
                    } else if (llamada.verificacion_pendiente_vinculo === true) {
                        resultados.appendChild(
                            crearBadgeResultado(
                                'Verificación en proceso',
                                'is-verification-pending'
                            )
                        );
                    }

                    pie.appendChild(resultados);

                    const derecha = document.createElement('div');
                    derecha.className = 'linkage-call-history-meta';

                    const duracion = document.createElement('span');
                    duracion.className = 'linkage-call-recording-duration';
                    duracion.innerHTML = '<i class="bi bi-clock"></i><span></span>';
                    duracion.querySelector('span').textContent =
                        duracionLegible(llamada.duracion_segundos);
                    derecha.appendChild(duracion);

                    const pbxVerificado = /^[A-Za-z0-9_-]{6,100}$/.test(
                        String(llamada.grabacion_transferida_pbx || '')
                    ) ? String(llamada.grabacion_transferida_pbx) : '';
                    const grabacionSinVincular = llamada.grabacion_sin_vincular === true;
                    let panelAudio = null;
                    if (pbxVerificado) {
                        const button = document.createElement('button');
                        button.type = 'button';
                        button.className = 'linkage-call-audio-state is-available linkage-call-recording-toggle';
                        button.innerHTML = '<i class="bi bi-record-circle" aria-hidden="true"></i>' +
                            '<span>Consultar grabación</span>' +
                            '<i class="bi bi-chevron-down linkage-call-recording-chevron" aria-hidden="true"></i>';
                        button.setAttribute('aria-expanded', 'false');
                        derecha.appendChild(button);
                        const panel = document.createElement('div');
                        panel.className = 'linkage-call-inline-player linkage-incoming-audio-panel';
                        panel.hidden = true;
                        const host = document.createElement('div');
                        const errorBox = document.createElement('p');
                        errorBox.className = 'linkage-incoming-player-error';
                        errorBox.hidden = true;
                        errorBox.setAttribute('role', 'status');
                        panel.append(host, errorBox);
                        button.addEventListener('click', async () => {
                            if (!panel.hidden) {
                                panel.querySelectorAll('audio').forEach(a => a.pause());
                                panel.hidden = true;
                                button.setAttribute('aria-expanded', 'false');
                                return;
                            }
                            button.disabled = true;
                            errorBox.hidden = true;
                            try {
                                const institucionSaliente=llamada.grabacion_transferida_institucion===true;
                                const url = new URL(
                                    institucionSaliente
                                        ? 'prueba_telefonia/api/grabacion_transferida_institucion.php'
                                        : 'prueba_telefonia/api/grabacion_transferida_pbx.php',
                                    document.baseURI
                                );
                                if(institucionSaliente)url.searchParams.set('seguimiento_id',String(llamada.seguimiento_id||0));
                                url.searchParams.set('pbx_call_id', pbxVerificado);
                                url.searchParams.set('verificar', '1');
                                const response = await fetch(url.toString(), {
                                    credentials: 'same-origin', cache: 'no-store'
                                });
                                if (!response.ok) throw new Error(
                                    'No fue posible comprobar los permisos de esta grabación.'
                                );
                                const info = await response.json();
                                if (!info.ok || !info.disponible ||
                                    !Number.isInteger(info.segmentos) || info.segmentos < 1 ||
                                    !window.IMPE_TELEPHONY_SEGMENTS?.create(
                                        host, url.toString(), info.segmentos, info.segmentos_info||[]
                                    )) {
                                    throw new Error('La grabación todavía no está disponible en Zadarma.');
                                }
                            } catch (error) {
                                errorBox.textContent = error.message || 'Audio no disponible.';
                                errorBox.hidden = false;
                            } finally {
                                panel.hidden = false;
                                button.disabled = false;
                                button.setAttribute('aria-expanded', 'true');
                            }
                        });
                        panelAudio = panel;
                    } else {
                        const estadoAudio = document.createElement('span');
                        estadoAudio.className = llamada.tiene_grabacion
                            ? 'linkage-call-audio-state is-available'
                            : ((llamada.grabacion_procesando || grabacionSinVincular)
                                ? 'linkage-call-audio-state is-processing'
                                : 'linkage-call-audio-state');
                        estadoAudio.innerHTML = llamada.tiene_grabacion
                            ? '<i class="bi bi-record-circle"></i><span>Grabación disponible</span>'
                            : (grabacionSinVincular
                                ? '<i class="bi bi-link-45deg"></i><span>Grabación no vinculada</span>'
                                : (llamada.grabacion_procesando
                                    ? '<i class="bi bi-hourglass-split"></i><span>Procesando grabación</span>'
                                    : (llamada.excluir_grabacion
                                        ? '<i class="bi bi-mic-mute"></i><span>Solo historial</span>'
                                        : '<i class="bi bi-mic-mute"></i><span>Sin grabación</span>')));
                        if (grabacionSinVincular) {
                            estadoAudio.title = 'No se confirmó un archivo de audio asociado a esta llamada en la centralita.';
                        }
                        derecha.appendChild(estadoAudio);
                        if(llamada.evidencia_grabacion_pendiente && !['NO_APLICA','REPORTADA','SOLO_HISTORIAL'].includes(String(llamada.estado_grabacion))){
                            estadoAudio.textContent='Grabación pendiente de confirmar';
                            estadoAudio.title=String(llamada.evidencia_grabacion_pendiente);
                            const pending=document.createElement('p');
                            pending.className='linkage-call-recording-notes';
                            pending.textContent=String(llamada.evidencia_grabacion_pendiente);
                            cuerpo.appendChild(pending);
                        }
                    }

                    const usuario = String(llamada.usuario || '').trim();
                    if (usuario) {
                        const registro = document.createElement('span');
                        registro.className = 'linkage-call-history-user';
                        registro.textContent = 'Registró: ' + usuario;
                        derecha.appendChild(registro);
                    }

                    pie.appendChild(derecha);
                    cuerpo.appendChild(pie);
                    if (panelAudio) cuerpo.appendChild(panelAudio);

                    tarjeta.appendChild(icono);
                    tarjeta.appendChild(cuerpo);
                    return tarjeta;
                };

                let reintentosGrabacion = 0;
                let temporizadorGrabacion = null;

                const cargar = function () {
                    const solicitud = window.IMPE_CONSULTAR_LLAMADAS_EXPEDIENTE
                        ? window.IMPE_CONSULTAR_LLAMADAS_EXPEDIENTE(seguimientoId)
                        : Promise.reject(new Error('No se pudo inicializar el historial.'));
                    solicitud
                        .then(function (data) {
                            const llamadas = Array.isArray(data.llamadas) ? data.llamadas : [];
                            const grabaciones = llamadas.filter(function (llamada) {
                                return Boolean((llamada.tiene_grabacion && llamada.grabacion_url) ||
                                    llamada.grabacion_transferida_pbx);
                            });
                            const hayGrabacionesProcesando = llamadas.some(function (llamada) {
                                return Boolean(llamada.grabacion_procesando);
                            });
                            const contactadas = llamadas.filter(function (llamada) {
                                const resultado = String(llamada.resultado || '').toUpperCase();
                                if (['CONTACTADO', 'SOLICITO_LLAMAR_DESPUES', 'MENSAJE_ENVIADO',
                                    'SOLICITO_INFORMACION', 'CONTACTO_CORRECTO', 'CONTACTO_REFERIDO']
                                    .includes(resultado)) return true;
                                // Una grabación PBX transferida verifica que existió
                                // conversación, pero no sustituye la clasificación manual.
                                return Boolean(llamada.grabacion_transferida_pbx) &&
                                    Number(llamada.duracion_segundos || 0) > 0 &&
                                    !['SIN_RESPUESTA', 'NO_CONTESTO', 'BUZON_VOZ', 'OCUPADO',
                                        'FUERA_SERVICIO', 'NUMERO_INCORRECTO'].includes(resultado);
                            }).length;

                            countCalls.textContent = String(llamadas.length);
                            summaryTotal.textContent = String(llamadas.length);
                            summaryContacted.textContent = String(contactadas);
                            summaryRecordings.textContent = String(grabaciones.length);

                            allCalls = llamadas;
                            renderHistory();

                            document.dispatchEvent(new CustomEvent(
                                'impe:expediente-llamadas-actualizadas',
                                {detail: {seguimientoId: seguimientoId, llamadas: llamadas}}
                            ));

                            window.clearTimeout(temporizadorGrabacion);
                            if (hayGrabacionesProcesando && reintentosGrabacion < 6) {
                                reintentosGrabacion++;
                                temporizadorGrabacion = window.setTimeout(cargar, 7000);
                            } else if (!hayGrabacionesProcesando) {
                                reintentosGrabacion = 0;
                            }
                        })
                        .catch(function (error) {
                            countCalls.textContent = '—';
                            summaryTotal.textContent = '—';
                            summaryContacted.textContent = '—';
                            summaryRecordings.textContent = '—';
                            historyList.innerHTML = '';
                            pagination.hidden = true;
                            pagination.replaceChildren();
                            historyList.appendChild(crearEstadoVacio(
                                error.message || 'No fue posible cargar las llamadas.',
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
