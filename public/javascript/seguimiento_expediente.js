(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        const parametros = new URLSearchParams(window.location.search);
        const esDetalle =
            parametros.get('controller') === 'seguimientoVinculacion' &&
            parametros.get('action') === 'detalle';
        const seguimientoId = Number(parametros.get('id') || 0);
        const nav = document.querySelector('.linkage-detail-tabs');

        if (!esDetalle || !nav || seguimientoId <= 0) {
            return;
        }

        document.body.classList.add('linkage-expediente-enhanced');
        nav.classList.add('data-section-nav', 'linkage-expediente-section-nav');

        const urlDatos =
            'index.php?controller=agendaReunion&action=expedienteSeguimiento&seguimiento_id=' +
            encodeURIComponent(seguimientoId);

        const normalizar = function (valor) {
            return String(valor || '')
                .normalize('NFD')
                .replace(/[\u0300-\u036f]/g, '')
                .trim()
                .toLowerCase();
        };

        const escapar = function (valor) {
            return String(valor == null ? '' : valor)
                .replaceAll('&', '&amp;')
                .replaceAll('<', '&lt;')
                .replaceAll('>', '&gt;')
                .replaceAll('"', '&quot;')
                .replaceAll("'", '&#039;');
        };

        const texto = function (valor, reserva) {
            const limpio = String(valor == null ? '' : valor).trim();
            return limpio !== '' ? limpio : (reserva || '—');
        };

        const fechaLegible = function (valor, soloFecha) {
            const cadena = String(valor || '').trim();
            if (!cadena) {
                return '—';
            }

            const coincidencia = cadena.match(
                /^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2}))?/
            );

            if (!coincidencia) {
                return cadena;
            }

            const meses = [
                'ene', 'feb', 'mar', 'abr', 'may', 'jun',
                'jul', 'ago', 'sep', 'oct', 'nov', 'dic'
            ];
            const resultado =
                coincidencia[3] + ' ' +
                meses[Math.max(0, Number(coincidencia[2]) - 1)] + ' ' +
                coincidencia[1];

            if (soloFecha || !coincidencia[4]) {
                return resultado;
            }

            return resultado + ' · ' + coincidencia[4] + ':' + coincidencia[5];
        };

        const etiquetaModalidad = function (valor) {
            const mapa = {
                VIRTUAL: 'Virtual',
                PRESENCIAL: 'Presencial',
                HIBRIDA: 'Híbrida'
            };
            return mapa[String(valor || '').toUpperCase()] || texto(valor);
        };

        const etiquetaEstadoReunion = function (valor) {
            const mapa = {
                SOLICITADA: 'Por confirmar',
                CAMBIO_SOLICITADO: 'Cambio solicitado',
                CONFIRMADA: 'Confirmada',
                CORREO_ENVIADO: 'Confirmación enviada',
                REALIZADA: 'Realizada',
                CANCELADA: 'Cancelada'
            };
            return mapa[String(valor || '').toUpperCase()] || texto(valor);
        };

        const claseEstadoReunion = function (valor) {
            const estado = String(valor || '').toUpperCase();
            if (estado === 'REALIZADA' || estado === 'CORREO_ENVIADO') {
                return 'is-success';
            }
            if (estado === 'CONFIRMADA') {
                return 'is-primary';
            }
            if (estado === 'CAMBIO_SOLICITADO') {
                return 'is-warning';
            }
            if (estado === 'CANCELADA') {
                return 'is-muted';
            }
            return 'is-pending';
        };

        const etiquetaResultadoReunion = function (valor) {
            const mapa = {
                AVANZAR_CONVENIO: 'Avanzar a convenio',
                REQUIERE_SEGUIMIENTO: 'Requiere seguimiento',
                NO_INTERESADO: 'No interesado'
            };
            return mapa[String(valor || '').toUpperCase()] || texto(valor);
        };

        const clavesPorEtiqueta = {
            'resumen': 'resumen',
            'contacto y validacion': 'contacto',
            'interacciones': 'interacciones',
            'oficio y correos': 'oficios',
            'agenda': 'agenda',
            'reunion': 'reunion',
            'convenio': 'convenio'
        };

        const tabs = Array.from(nav.querySelectorAll('span'));
        const secciones = Array.from(
            document.querySelectorAll('section.dashboard-panel.linkage-detail-panel')
        );

        if (tabs.length === 0 || secciones.length === 0) {
            return;
        }

        const encontrarSeccion = function (predicado) {
            return secciones.find(predicado) || null;
        };

        const seccionResumen = encontrarSeccion(function (seccion) {
            return Boolean(seccion.querySelector('.linkage-detail-header'));
        });
        const seccionInformacion = encontrarSeccion(function (seccion) {
            const titulo = seccion.querySelector('.users-list-header h2');
            return normalizar(titulo?.textContent) === 'informacion encontrada';
        });
        const seccionVerificados = encontrarSeccion(function (seccion) {
            const titulo = seccion.querySelector('.users-list-header h2');
            return normalizar(titulo?.textContent) === 'datos verificados';
        });
        const seccionInteracciones = encontrarSeccion(function (seccion) {
            const titulo = seccion.querySelector('.panel-title');
            return normalizar(titulo?.textContent) === 'historial de interacciones';
        });
        const seccionOficios = encontrarSeccion(function (seccion) {
            const titulo = seccion.querySelector('.panel-title');
            return normalizar(titulo?.textContent) === 'oficios';
        });
        const seccionObservaciones = encontrarSeccion(function (seccion) {
            const titulo = seccion.querySelector('.users-list-header h2');
            return normalizar(titulo?.textContent) === 'observaciones del cuenta clave';
        });

        nav.setAttribute('role', 'tablist');
        nav.setAttribute('aria-label', 'Secciones del expediente');

        const contenido = document.createElement('div');
        contenido.className = 'linkage-expediente-content';

        const primeraSeccion = secciones[0];
        primeraSeccion.parentNode.insertBefore(contenido, primeraSeccion);

        const paneles = {};
        const crearPanel = function (clave) {
            const panel = document.createElement('div');
            panel.className = 'linkage-expediente-pane';
            panel.dataset.expedientePane = clave;
            panel.setAttribute('role', 'tabpanel');
            panel.hidden = true;
            contenido.appendChild(panel);
            paneles[clave] = panel;
            return panel;
        };

        ['resumen', 'contacto', 'interacciones', 'oficios', 'agenda', 'reunion', 'convenio']
            .forEach(crearPanel);

        const mover = function (seccion, clave) {
            if (!seccion || !paneles[clave]) {
                return;
            }

            seccion.classList.add('linkage-expediente-section');
            paneles[clave].appendChild(seccion);
        };

        mover(seccionResumen, 'resumen');
        mover(seccionObservaciones, 'resumen');
        mover(seccionInformacion, 'contacto');
        mover(seccionVerificados, 'contacto');
        mover(seccionInteracciones, 'interacciones');
        mover(seccionOficios, 'oficios');

        const renderizarVacio = function (clave, icono, titulo, descripcion, etiquetaEstado) {
            const panel = paneles[clave];
            if (!panel) {
                return;
            }

            panel.innerHTML =
                '<section class="dashboard-panel linkage-detail-panel linkage-expediente-empty">' +
                    '<div class="linkage-expediente-empty-content">' +
                        '<span class="linkage-expediente-empty-icon">' +
                            '<i class="bi ' + escapar(icono) + '"></i>' +
                        '</span>' +
                        (etiquetaEstado
                            ? '<span class="linkage-expediente-status is-muted">' + escapar(etiquetaEstado) + '</span>'
                            : '') +
                        '<h3>' + escapar(titulo) + '</h3>' +
                        '<p>' + escapar(descripcion) + '</p>' +
                    '</div>' +
                '</section>';
        };

        renderizarVacio(
            'agenda',
            'bi-calendar3',
            'Consultando agenda',
            'Estamos cargando la coordinación de reuniones de este seguimiento.'
        );
        renderizarVacio(
            'reunion',
            'bi-people',
            'Consultando reunión',
            'Estamos cargando el resultado y los acuerdos registrados.'
        );
        renderizarVacio(
            'convenio',
            'bi-file-earmark-text',
            'Consultando convenio',
            'Estamos cargando el estado actual de formalización.'
        );

        const mejorarHistorial = function () {
            if (!seccionInteracciones) {
                return;
            }

            const mapaIconos = {
                sistema: 'bi-gear',
                llamada: 'bi-telephone',
                whatsapp: 'bi-whatsapp',
                correo: 'bi-envelope',
                nota: 'bi-journal-text'
            };

            seccionInteracciones
                .querySelectorAll('.linkage-history-item')
                .forEach(function (item) {
                    if (item.querySelector('.linkage-expediente-history-icon')) {
                        return;
                    }

                    const encabezado = item.querySelector('div');
                    const canal = normalizar(encabezado?.querySelector('strong')?.textContent);
                    const icono = document.createElement('span');
                    icono.className = 'linkage-expediente-history-icon';
                    icono.innerHTML = '<i class="bi ' + (mapaIconos[canal] || 'bi-dot') + '"></i>';
                    item.insertBefore(icono, item.firstChild);
                });
        };

        const limitarHistorial = function () {
            if (!seccionInteracciones) {
                return;
            }

            const lista = seccionInteracciones.querySelector('.linkage-history-list');
            const items = lista
                ? Array.from(lista.querySelectorAll('.linkage-history-item'))
                : [];
            const limite = 6;

            if (!lista || items.length <= limite) {
                return;
            }

            items.slice(limite).forEach(function (item) {
                item.classList.add('is-history-hidden');
            });

            const pie = document.createElement('div');
            pie.className = 'linkage-history-more';

            const resumen = document.createElement('span');
            resumen.textContent = 'Mostrando ' + limite + ' de ' + items.length + ' interacciones';

            const boton = document.createElement('button');
            boton.type = 'button';
            boton.className = 'btn btn-system-light';
            boton.innerHTML =
                '<i class="bi bi-chevron-down me-1"></i>' +
                'Ver ' + (items.length - limite) + ' más';

            let expandido = false;
            boton.addEventListener('click', function () {
                expandido = !expandido;

                items.slice(limite).forEach(function (item) {
                    item.classList.toggle('is-history-hidden', !expandido);
                });

                resumen.textContent = expandido
                    ? 'Mostrando las ' + items.length + ' interacciones'
                    : 'Mostrando ' + limite + ' de ' + items.length + ' interacciones';
                boton.innerHTML = expandido
                    ? '<i class="bi bi-chevron-up me-1"></i>Ver menos'
                    : '<i class="bi bi-chevron-down me-1"></i>Ver ' +
                        (items.length - limite) + ' más';
            });

            pie.appendChild(resumen);
            pie.appendChild(boton);
            seccionInteracciones.appendChild(pie);
        };

        mejorarHistorial();
        limitarHistorial();

        tabs.forEach(function (tab, indice) {
            const etiqueta = normalizar(tab.textContent);
            const clave = clavesPorEtiqueta[etiqueta] || 'resumen';

            tab.dataset.expedienteTab = clave;
            tab.id = 'expediente-tab-' + clave;
            tab.setAttribute('role', 'tab');
            tab.setAttribute('aria-controls', 'expediente-panel-' + clave);
            tab.setAttribute('aria-selected', 'false');
            tab.tabIndex = indice === 0 ? 0 : -1;

            if (paneles[clave]) {
                paneles[clave].id = 'expediente-panel-' + clave;
                paneles[clave].setAttribute('aria-labelledby', tab.id);
            }
        });

        const activar = function (clave, actualizarHash, acomodarVista) {
            if (!paneles[clave]) {
                clave = 'resumen';
            }

            tabs.forEach(function (tab) {
                const activo = tab.dataset.expedienteTab === clave;
                tab.classList.toggle('active', activo);
                tab.setAttribute('aria-selected', activo ? 'true' : 'false');
                tab.tabIndex = activo ? 0 : -1;

                if (activo) {
                    tab.scrollIntoView({
                        behavior: 'smooth',
                        block: 'nearest',
                        inline: 'nearest'
                    });
                }
            });

            Object.keys(paneles).forEach(function (panelClave) {
                const activo = panelClave === clave;
                paneles[panelClave].hidden = !activo;
                paneles[panelClave].setAttribute('aria-hidden', activo ? 'false' : 'true');
            });

            if (actualizarHash) {
                history.replaceState(null, '', '#exp-' + clave);
            }

            if (acomodarVista) {
                const posicion = nav.getBoundingClientRect().top + window.scrollY - 78;
                window.scrollTo({ top: Math.max(0, posicion), behavior: 'smooth' });
            }
        };

        tabs.forEach(function (tab) {
            tab.addEventListener('click', function () {
                activar(tab.dataset.expedienteTab || 'resumen', true, true);
            });

            tab.addEventListener('keydown', function (event) {
                const indice = tabs.indexOf(tab);
                let destino = null;

                if (event.key === 'ArrowRight') {
                    destino = tabs[(indice + 1) % tabs.length];
                } else if (event.key === 'ArrowLeft') {
                    destino = tabs[(indice - 1 + tabs.length) % tabs.length];
                } else if (event.key === 'Enter' || event.key === ' ') {
                    event.preventDefault();
                    activar(tab.dataset.expedienteTab || 'resumen', true, true);
                    return;
                }

                if (destino) {
                    event.preventDefault();
                    destino.focus();
                    activar(destino.dataset.expedienteTab || 'resumen', true, true);
                }
            });
        });

        const actualizarFilaResumen = function (etiquetaBuscada, nuevaEtiqueta, valor) {
            if (!seccionResumen) {
                return;
            }

            const fila = Array.from(seccionResumen.querySelectorAll('.detail-row')).find(function (item) {
                return normalizar(item.querySelector('span')?.textContent) === etiquetaBuscada;
            });

            if (!fila) {
                return;
            }

            const etiqueta = fila.querySelector('span');
            const fuerte = fila.querySelector('strong');
            if (etiqueta && nuevaEtiqueta) {
                etiqueta.textContent = nuevaEtiqueta;
            }
            if (fuerte) {
                fuerte.textContent = texto(valor);
            }
        };

        const renderizarResumen = function (datos) {
            const flujo = datos?.flujo || null;
            if (!flujo || !seccionResumen) {
                return;
            }

            const principal = flujo.accion_principal || null;
            const etapa = texto(flujo.titulo, 'Seguimiento en curso');
            const proxima = principal?.etiqueta
                ? texto(principal.etiqueta)
                : (etapa === 'Convenio formalizado' ? 'Sin acción pendiente' : etapa);
            const paso = 'Paso ' + Number(flujo.paso_actual || 0) + ' de ' + Number(flujo.total_pasos || 13);

            actualizarFilaResumen('etapa actual', 'Etapa actual', etapa);
            actualizarFilaResumen('analista', 'Ruta de vinculación', paso);
            actualizarFilaResumen('proxima accion', 'Próxima acción', proxima);

            const badge = document.querySelector('.linkage-panel .linkage-state-pill');
            if (badge) {
                badge.textContent = etapa;
            }

            let tarjeta = seccionResumen.querySelector('[data-expediente-route-card]');
            if (!tarjeta) {
                tarjeta = document.createElement('div');
                tarjeta.className = 'linkage-expediente-route-card';
                tarjeta.setAttribute('data-expediente-route-card', '');
                seccionResumen.appendChild(tarjeta);
            }

            const ultima = datos?.ultima_interaccion || {};
            const ultimaMeta = ultima?.fecha_inicio
                ? fechaLegible(ultima.fecha_inicio) + ' · ' + texto(ultima.canal, 'Sistema')
                : 'Sin actividad reciente';
            const ultimaNota = texto(ultima?.notas, 'Todavía no hay una actividad reciente registrada.');

            tarjeta.innerHTML =
                '<div class="linkage-expediente-route-heading">' +
                    '<div>' +
                        '<span class="linkage-expediente-eyebrow">Ruta de vinculación</span>' +
                        '<strong>' + escapar(etapa) + '</strong>' +
                    '</div>' +
                    '<span class="linkage-expediente-step-pill">' + escapar(paso) + '</span>' +
                '</div>' +
                '<div class="linkage-expediente-progress" aria-hidden="true">' +
                    '<span style="width:' + Math.max(0, Math.min(100, Number(flujo.porcentaje || 0))) + '%"></span>' +
                '</div>' +
                '<p class="linkage-expediente-route-description">' + escapar(texto(flujo.descripcion, '')) + '</p>' +
                '<div class="linkage-expediente-last-activity">' +
                    '<span class="linkage-expediente-last-icon"><i class="bi bi-clock-history"></i></span>' +
                    '<div>' +
                        '<small>Última actividad · ' + escapar(ultimaMeta) + '</small>' +
                        '<strong>' + escapar(ultimaNota) + '</strong>' +
                    '</div>' +
                '</div>';
        };

        const dato = function (etiqueta, valor, anchoCompleto) {
            return '<div class="linkage-expediente-data-item' + (anchoCompleto ? ' is-wide' : '') + '">' +
                '<span>' + escapar(etiqueta) + '</span>' +
                '<strong>' + escapar(texto(valor)) + '</strong>' +
            '</div>';
        };

        const renderizarAgenda = function (datos) {
            const reuniones = Array.isArray(datos?.reuniones) ? datos.reuniones : [];
            const reprogramaciones = Array.isArray(datos?.reprogramaciones)
                ? datos.reprogramaciones
                : [];

            if (reuniones.length === 0) {
                renderizarVacio(
                    'agenda',
                    'bi-calendar3',
                    'Sin reunión agendada',
                    'Todavía no se ha registrado una coordinación de reunión para este seguimiento.',
                    'Sin agenda'
                );
                return;
            }

            const reunion = reuniones[0];
            const enlace = texto(reunion.zoom_url, '');
            const ubicacion = texto(reunion.ubicacion, '');
            const destino = enlace || ubicacion || 'Por definir';
            const puedeAbrir = /^https?:\/\//i.test(enlace);
            const eventos = [];

            if (reunion.created_at) {
                eventos.push(['Solicitud creada', reunion.created_at]);
            }
            if (reunion.confirmada_at) {
                eventos.push(['Confirmada por Cuenta Clave', reunion.confirmada_at]);
            }
            if (reunion.correo_confirmacion_at) {
                eventos.push(['Correo de confirmación registrado', reunion.correo_confirmacion_at]);
            }

            const historial = eventos.length > 0
                ? '<div class="linkage-expediente-mini-timeline">' +
                    eventos.map(function (evento) {
                        return '<div><span></span><strong>' + escapar(evento[0]) + '</strong><small>' +
                            escapar(fechaLegible(evento[1])) + '</small></div>';
                    }).join('') +
                  '</div>'
                : '';

            const reprogramacion = reprogramaciones[0] || null;
            const bloqueReprogramacion = reprogramacion
                ? '<div class="linkage-expediente-reprogramacion">' +
                    '<div><i class="bi bi-arrow-repeat"></i><strong>Reprogramación registrada</strong></div>' +
                    '<p>' +
                        escapar(fechaLegible(reprogramacion.fecha_anterior)) +
                        ' → ' + escapar(fechaLegible(reprogramacion.fecha_nueva)) +
                    '</p>' +
                    '<small>' + escapar(texto(reprogramacion.motivo, 'Sin motivo adicional.')) + '</small>' +
                  '</div>'
                : '';

            paneles.agenda.innerHTML =
                '<section class="dashboard-panel linkage-detail-panel linkage-expediente-module">' +
                    '<div class="linkage-expediente-module-heading">' +
                        '<div>' +
                            '<span class="linkage-expediente-eyebrow">Coordinación más reciente</span>' +
                            '<h3>Reunión de vinculación</h3>' +
                            '<p>Información compartida entre Analista y Cuenta Clave.</p>' +
                        '</div>' +
                        '<span class="linkage-expediente-status ' + claseEstadoReunion(reunion.estado) + '">' +
                            escapar(etiquetaEstadoReunion(reunion.estado)) +
                        '</span>' +
                    '</div>' +
                    '<div class="linkage-expediente-data-grid">' +
                        dato('Fecha y hora', fechaLegible(reunion.fecha_propuesta)) +
                        dato('Modalidad', etiquetaModalidad(reunion.modalidad)) +
                        dato('Duración', reunion.duracion_minutos ? reunion.duracion_minutos + ' min' : '—') +
                        dato('Cuenta Clave', reunion.cuenta_clave_nombre) +
                        dato('Objetivo', reunion.objetivo, true) +
                        '<div class="linkage-expediente-data-item is-wide">' +
                            '<span>Enlace / ubicación</span>' +
                            (puedeAbrir
                                ? '<a href="' + escapar(enlace) + '" target="_blank" rel="noopener noreferrer">' +
                                    '<i class="bi bi-box-arrow-up-right"></i>' + escapar(destino) + '</a>'
                                : '<strong>' + escapar(destino) + '</strong>') +
                        '</div>' +
                    '</div>' +
                    historial +
                    bloqueReprogramacion +
                '</section>';
        };

        const renderizarReunion = function (datos) {
            const post = datos?.post_envio || {};
            const reuniones = Array.isArray(datos?.reuniones) ? datos.reuniones : [];
            const reunion = reuniones[0] || {};
            const realizada = texto(post.reunion_realizada_at, '') !== '';

            if (!realizada) {
                if (Object.keys(reunion).length > 0) {
                    paneles.reunion.innerHTML =
                        '<section class="dashboard-panel linkage-detail-panel linkage-expediente-module">' +
                            '<div class="linkage-expediente-module-heading">' +
                                '<div>' +
                                    '<span class="linkage-expediente-eyebrow">Reunión</span>' +
                                    '<h3>Reunión programada</h3>' +
                                    '<p>Aún no se ha registrado el resultado de esta reunión.</p>' +
                                '</div>' +
                                '<span class="linkage-expediente-status ' + claseEstadoReunion(reunion.estado) + '">' +
                                    escapar(etiquetaEstadoReunion(reunion.estado)) +
                                '</span>' +
                            '</div>' +
                            '<div class="linkage-expediente-data-grid">' +
                                dato('Fecha programada', fechaLegible(reunion.fecha_propuesta)) +
                                dato('Modalidad', etiquetaModalidad(reunion.modalidad)) +
                                dato('Objetivo', reunion.objetivo, true) +
                            '</div>' +
                        '</section>';
                    return;
                }

                renderizarVacio(
                    'reunion',
                    'bi-people',
                    'Sin reunión realizada',
                    'Cuando se realice una reunión, aquí aparecerán el resultado, los acuerdos y el seguimiento posterior.',
                    'Pendiente'
                );
                return;
            }

            const resultado = etiquetaResultadoReunion(post.reunion_resultado);
            const proxima = texto(datos?.resumen?.proxima_accion_at, '');
            const bloqueSeguimiento =
                String(post.reunion_resultado || '').toUpperCase() === 'REQUIERE_SEGUIMIENTO' && proxima
                    ? '<div class="linkage-expediente-followup">' +
                        '<span><i class="bi bi-calendar-check"></i></span>' +
                        '<div><small>Seguimiento posterior</small><strong>' +
                            escapar(fechaLegible(proxima)) + '</strong></div>' +
                      '</div>'
                    : '';

            paneles.reunion.innerHTML =
                '<section class="dashboard-panel linkage-detail-panel linkage-expediente-module">' +
                    '<div class="linkage-expediente-module-heading">' +
                        '<div>' +
                            '<span class="linkage-expediente-eyebrow">Resultado registrado</span>' +
                            '<h3>Reunión realizada</h3>' +
                            '<p>Resultado operativo y acuerdos capturados por el Analista.</p>' +
                        '</div>' +
                        '<span class="linkage-expediente-status is-success">Realizada</span>' +
                    '</div>' +
                    '<div class="linkage-expediente-data-grid">' +
                        dato('Fecha programada', fechaLegible(post.reunion_fecha || reunion.fecha_propuesta)) +
                        dato('Registro de resultado', fechaLegible(post.reunion_realizada_at)) +
                        dato('Resultado actual', resultado) +
                        dato('Modalidad', etiquetaModalidad(post.reunion_modalidad || reunion.modalidad)) +
                        dato('Acuerdos / notas', post.reunion_resultado_notas, true) +
                    '</div>' +
                    bloqueSeguimiento +
                '</section>';
        };

        const renderizarConvenio = function (datos) {
            const post = datos?.post_envio || {};
            const flujo = datos?.flujo || {};
            const formalizado = texto(post.convenio_formalizado_at, '') !== '';

            if (!formalizado) {
                const reunionRealizada = texto(post.reunion_realizada_at, '') !== '';
                renderizarVacio(
                    'convenio',
                    'bi-file-earmark-text',
                    reunionRealizada ? 'Pendiente de formalización' : 'Convenio aún no iniciado',
                    reunionRealizada
                        ? texto(
                            flujo.descripcion,
                            'La institución avanzó después de la reunión, pero todavía no hay un convenio formalizado registrado.'
                          )
                        : 'El convenio aparecerá aquí cuando la ruta avance después de la reunión.',
                    reunionRealizada ? 'Pendiente' : 'Sin registro'
                );
                return;
            }

            paneles.convenio.innerHTML =
                '<section class="dashboard-panel linkage-detail-panel linkage-expediente-module">' +
                    '<div class="linkage-expediente-module-heading">' +
                        '<div>' +
                            '<span class="linkage-expediente-eyebrow">Cierre de la ruta</span>' +
                            '<h3>Convenio formalizado</h3>' +
                            '<p>Información registrada al concluir la ruta de vinculación del Analista.</p>' +
                        '</div>' +
                        '<span class="linkage-expediente-status is-success">Formalizado</span>' +
                    '</div>' +
                    '<div class="linkage-expediente-data-grid">' +
                        dato('Fecha del convenio', fechaLegible(post.convenio_fecha, true)) +
                        dato('Referencia', post.convenio_referencia) +
                        dato('Registrado', fechaLegible(post.convenio_formalizado_at)) +
                        dato('Observaciones', post.convenio_notas, true) +
                    '</div>' +
                '</section>';
        };

        const cargarDatos = async function () {
            try {
                const respuesta = await fetch(urlDatos, {
                    headers: { 'X-Requested-With': 'fetch' },
                    cache: 'no-store'
                });
                const datos = await respuesta.json();

                if (!respuesta.ok || !datos.ok) {
                    throw new Error(datos.mensaje || 'No fue posible cargar el expediente operativo.');
                }

                renderizarResumen(datos);
                renderizarAgenda(datos);
                renderizarReunion(datos);
                renderizarConvenio(datos);
            } catch (error) {
                console.error(error);
                renderizarVacio(
                    'agenda',
                    'bi-exclamation-circle',
                    'No fue posible cargar la agenda',
                    'La información general del expediente sigue disponible.',
                    'Sin conexión'
                );
                renderizarVacio(
                    'reunion',
                    'bi-exclamation-circle',
                    'No fue posible cargar la reunión',
                    'La información general del expediente sigue disponible.',
                    'Sin conexión'
                );
                renderizarVacio(
                    'convenio',
                    'bi-exclamation-circle',
                    'No fue posible cargar el convenio',
                    'La información general del expediente sigue disponible.',
                    'Sin conexión'
                );
            }
        };

        const hash = String(window.location.hash || '').replace('#exp-', '');
        activar(paneles[hash] ? hash : 'resumen', false, false);
        cargarDatos();
    });
})();
