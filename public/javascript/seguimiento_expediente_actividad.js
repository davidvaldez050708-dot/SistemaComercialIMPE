(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        window.setTimeout(function () {
            const parametros = new URLSearchParams(window.location.search);
            const esDetalle =
                parametros.get('controller') === 'seguimientoVinculacion' &&
                parametros.get('action') === 'detalle';

            if (!esDetalle) {
                return;
            }

            const normalizar = function (valor) {
                return String(valor || '')
                    .normalize('NFD')
                    .replace(/[\u0300-\u036f]/g, '')
                    .trim()
                    .toLowerCase();
            };

            const meses = [
                'ene', 'feb', 'mar', 'abr', 'may', 'jun',
                'jul', 'ago', 'sep', 'oct', 'nov', 'dic'
            ];

            const fechaInternaLegible = function (texto) {
                return String(texto || '').replace(
                    /\b(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})(?::\d{2})?\b/g,
                    function (_, anio, mes, dia, hora, minuto) {
                        const indiceMes = Math.max(0, Math.min(11, Number(mes) - 1));
                        return dia + ' ' + meses[indiceMes] + ' ' + anio + ' · ' + hora + ':' + minuto;
                    }
                );
            };

            const extraerLineasDetalle = function (nodo) {
                if (!nodo) {
                    return [];
                }

                const lineas = [];
                let actual = '';

                const recorrer = function (padre) {
                    Array.from(padre.childNodes).forEach(function (hijo) {
                        if (hijo.nodeType === Node.TEXT_NODE) {
                            actual += String(hijo.nodeValue || '');
                            return;
                        }

                        if (hijo.nodeName === 'BR') {
                            if (actual.trim()) {
                                lineas.push(actual.trim());
                            }
                            actual = '';
                            return;
                        }

                        recorrer(hijo);
                    });
                };

                recorrer(nodo);

                if (actual.trim()) {
                    lineas.push(actual.trim());
                }

                return lineas
                    .map(fechaInternaLegible)
                    .filter(Boolean);
            };

            const descomponerDetalle = function (nodo) {
                const lineas = extraerLineasDetalle(nodo);
                let contacto = '';

                if (lineas.length > 0) {
                    const coincidencia = lineas[0].match(
                        /^(?:Persona\s+atendi[oó]|Atendi[oó]|Contacto)\s*:\s*(.+)$/i
                    );

                    if (coincidencia) {
                        contacto = String(coincidencia[1] || '').trim();
                        lineas.shift();
                    }
                }

                return {
                    contacto: contacto,
                    detalle: lineas.join('\n').trim()
                };
            };

            const descomponerMeta = function (texto) {
                const partes = String(texto || '')
                    .split('·')
                    .map(function (parte) {
                        return parte.trim();
                    })
                    .filter(Boolean);

                let fecha = '';
                if (
                    partes.length >= 2 &&
                    /\b\d{4}\b/.test(partes[0]) &&
                    /^\d{1,2}:\d{2}$/.test(partes[1])
                ) {
                    fecha = partes.splice(0, 2).join(' · ');
                } else if (partes.length > 0) {
                    fecha = partes.shift();
                }

                return {
                    fecha: fecha,
                    estados: partes
                };
            };

            const crearFilaDetalle = function (etiqueta, valor) {
                const texto = String(valor || '').trim();
                if (!texto) {
                    return null;
                }

                const fila = document.createElement('div');
                fila.className = 'linkage-activity-detail-row';

                const label = document.createElement('span');
                label.className = 'linkage-activity-detail-label';
                label.textContent = etiqueta;

                const contenido = document.createElement('span');
                contenido.className = 'linkage-activity-detail-value';
                contenido.textContent = texto;

                fila.appendChild(label);
                fila.appendChild(contenido);
                return fila;
            };

            const estructurarActividad = function (item, titulo, detalle, metadata) {
                if (!item || !titulo) {
                    return;
                }

                const tituloTexto = String(titulo.textContent || 'Actividad').trim();
                const metaInfo = descomponerMeta(metadata?.textContent || '');
                const informacion = descomponerDetalle(detalle);

                const contenido = document.createElement('div');
                contenido.className = 'linkage-activity-content';

                const cabecera = document.createElement('div');
                cabecera.className = 'linkage-activity-header';

                const tituloNuevo = document.createElement('strong');
                tituloNuevo.className = 'linkage-activity-title';
                tituloNuevo.textContent = tituloTexto;
                cabecera.appendChild(tituloNuevo);

                if (metaInfo.fecha) {
                    const fecha = document.createElement('span');
                    fecha.className = 'linkage-activity-date';
                    fecha.textContent = metaInfo.fecha;
                    cabecera.appendChild(fecha);
                }

                contenido.appendChild(cabecera);

                const cuerpo = document.createElement('div');
                cuerpo.className = 'linkage-activity-detail';

                const contacto = crearFilaDetalle('Contacto', informacion.contacto);
                if (contacto) {
                    cuerpo.appendChild(contacto);
                }

                const valorDetalle =
                    informacion.detalle &&
                    normalizar(informacion.detalle) !== 'sin detalle adicional.'
                        ? informacion.detalle
                        : '';

                const filaDetalle = crearFilaDetalle('Detalle', valorDetalle);
                if (filaDetalle) {
                    cuerpo.appendChild(filaDetalle);
                }

                if (cuerpo.children.length > 0) {
                    contenido.appendChild(cuerpo);
                }

                if (metaInfo.estados.length > 0) {
                    const pie = document.createElement('div');
                    pie.className = 'linkage-activity-footer';

                    metaInfo.estados.forEach(function (estado) {
                        const badge = document.createElement('span');
                        const normalizado = normalizar(estado);
                        badge.className = 'linkage-activity-badge';

                        if (normalizado === 'contacto efectivo') {
                            badge.classList.add('is-contact');
                        } else if (
                            normalizado === 'sin contacto' ||
                            normalizado === 'sin respuesta'
                        ) {
                            badge.classList.add('is-warning');
                        } else if (normalizado === 'sistema') {
                            badge.classList.add('is-system');
                        } else {
                            badge.classList.add('is-result');
                        }

                        badge.textContent = estado;
                        pie.appendChild(badge);
                    });

                    contenido.appendChild(pie);
                }

                item.replaceChildren(contenido);
            };

            const nav = document.querySelector('.linkage-detail-tabs');
            const seccionActividad =
                document.querySelector('[data-expediente-activity-section]') ||
                Array.from(document.querySelectorAll('section.dashboard-panel.linkage-detail-panel'))
                    .find(function (seccion) {
                        const titulo = seccion.querySelector('.panel-title');
                        const etiqueta = normalizar(titulo?.textContent);
                        return etiqueta === 'actividad del expediente' ||
                            etiqueta === 'historial de interacciones';
                    });
            const panelActividad = document.querySelector(
                '.linkage-expediente-pane[data-expediente-pane="interacciones"]'
            );

            if (!nav || !seccionActividad || !panelActividad) {
                return;
            }

            /*
             * seguimiento_expediente.js nació con la etiqueta "Interacciones".
             * La actividad ahora es una cronología más amplia, así que alineamos
             * el DOM con ese panel sin duplicarlo dentro de Resumen.
             */
            if (seccionActividad.parentElement !== panelActividad) {
                panelActividad.appendChild(seccionActividad);
            }

            seccionActividad.classList.add('linkage-expediente-activity');

            const tituloActividad = seccionActividad.querySelector('.panel-title');
            if (tituloActividad) {
                tituloActividad.textContent = 'Actividad del expediente';
            }

            const tabs = Array.from(nav.querySelectorAll('span'));
            const tabResumen = tabs.find(function (tab) {
                return normalizar(tab.textContent) === 'resumen';
            });
            const tabActividad = tabs.find(function (tab) {
                const etiqueta = normalizar(tab.textContent);
                return etiqueta === 'actividad' || etiqueta === 'interacciones';
            });

            if (tabActividad) {
                tabActividad.textContent = 'Actividad';
                tabActividad.dataset.expedienteTab = 'interacciones';
                tabActividad.id = 'expediente-tab-interacciones';
                tabActividad.setAttribute('aria-controls', 'expediente-panel-interacciones');
                panelActividad.id = 'expediente-panel-interacciones';
                panelActividad.setAttribute('aria-labelledby', tabActividad.id);

                /* El script base pudo haber tratado "Actividad" como Resumen. */
                if (panelActividad.hidden) {
                    tabActividad.classList.remove('active');
                    tabActividad.setAttribute('aria-selected', 'false');
                } else {
                    tabActividad.classList.add('active');
                    tabActividad.setAttribute('aria-selected', 'true');
                    if (tabResumen) {
                        tabResumen.classList.remove('active');
                        tabResumen.setAttribute('aria-selected', 'false');
                    }
                }
            }

            const clasificarActividad = function (item) {
                const tipoOriginal = normalizar(item.dataset.activityType || '');
                const titulo = item.querySelector('div > strong');
                const detalle = item.querySelector(':scope > p');
                const contenido = normalizar(detalle?.textContent);
                let tipo = tipoOriginal || 'actividad';
                let icono = 'bi-activity';
                let tituloSemantico = titulo?.textContent || 'Actividad';

                if (tipoOriginal === 'inicio') {
                    icono = 'bi-play-circle';
                    tituloSemantico = 'Seguimiento iniciado';
                } else if (tipoOriginal === 'verificacion') {
                    icono = 'bi-patch-check';
                    tituloSemantico = 'Información verificada';
                } else if (tipoOriginal === 'llamada_ip') {
                    icono = 'bi-telephone';
                } else if (tipoOriginal === 'whatsapp') {
                    icono = 'bi-whatsapp';
                } else if (tipoOriginal === 'correo') {
                    icono = 'bi-envelope';
                } else if (tipoOriginal === 'nota') {
                    icono = 'bi-journal-text';
                } else if (tipoOriginal === 'sistema') {
                    tipo = 'sistema';
                    icono = 'bi-gear';
                    tituloSemantico = 'Actividad del sistema';
                }

                if (contenido.includes('nueva propuesta de reunion enviada')) {
                    tipo = 'reunion';
                    icono = 'bi-calendar-plus';
                    tituloSemantico = 'Nueva propuesta de reunión enviada';
                } else if (contenido.includes('solicitud de reunion enviada')) {
                    tipo = 'reunion';
                    icono = 'bi-calendar-plus';
                    tituloSemantico = 'Solicitud de reunión enviada';
                } else if (contenido.includes('solicito reprogramar')) {
                    tipo = 'reunion';
                    icono = 'bi-arrow-repeat';
                    tituloSemantico = 'Reprogramación solicitada';
                } else if (
                    contenido.includes('correo de confirmacion de reunion') &&
                    contenido.includes('enviado')
                ) {
                    tipo = 'correo';
                    icono = 'bi-envelope-check';
                    tituloSemantico = 'Confirmación de reunión enviada';
                } else if (contenido.includes('confirmo la reunion')) {
                    tipo = 'reunion';
                    icono = 'bi-calendar-check';
                    tituloSemantico = 'Reunión confirmada';
                } else if (contenido.includes('oficio preparado')) {
                    tipo = 'oficio';
                    icono = 'bi-file-earmark-text';
                    tituloSemantico = 'Oficio preparado';
                } else if (contenido.includes('oficio/correo enviado')) {
                    tipo = 'correo';
                    icono = 'bi-send-check';
                    tituloSemantico = 'Oficio y correo enviados';
                } else if (contenido.includes('respuesta recibida')) {
                    tipo = 'correo';
                    icono = 'bi-reply';
                    tituloSemantico = 'Respuesta recibida';
                } else if (contenido.includes('seguimiento reactivado')) {
                    tipo = 'seguimiento';
                    icono = 'bi-arrow-counterclockwise';
                    tituloSemantico = 'Seguimiento reactivado';
                } else if (contenido.includes('reunion realizada')) {
                    tipo = 'reunion';
                    icono = 'bi-people';
                    tituloSemantico = 'Reunión realizada';
                } else if (contenido.includes('convenio formalizado')) {
                    tipo = 'convenio';
                    icono = 'bi-file-earmark-check';
                    tituloSemantico = 'Convenio formalizado';
                }

                if (titulo) {
                    titulo.textContent = tituloSemantico;
                }

                const metadata = item.querySelector('div > span');
                if (metadata) {
                    let meta = metadata.textContent.replace(/\s+/g, ' ').trim();

                    if (tipoOriginal === 'sistema') {
                        meta = meta.replace(/\s*·\s*Otro\s*$/i, '');
                        if (!/·\s*Sistema\s*$/i.test(meta)) {
                            meta += ' · Sistema';
                        }
                    }

                    metadata.textContent = meta;
                }

                estructurarActividad(item, titulo, detalle, metadata);
                item.dataset.activityVisualType = tipo;

                let iconoNodo = item.querySelector('.linkage-expediente-history-icon');
                if (!iconoNodo) {
                    iconoNodo = document.createElement('span');
                    iconoNodo.className = 'linkage-expediente-history-icon';
                    item.insertBefore(iconoNodo, item.firstChild);
                }
                iconoNodo.innerHTML = '<i class="bi ' + icono + '"></i>';
            };

            const lista = seccionActividad.querySelector('.linkage-history-list');
            const items = lista
                ? Array.from(lista.querySelectorAll('.linkage-history-item'))
                : [];

            items.forEach(clasificarActividad);

            const pieAnterior = seccionActividad.querySelector('.linkage-history-more');
            if (pieAnterior) {
                pieAnterior.remove();
            }

            const limite = 6;
            if (items.length > limite) {
                items.forEach(function (item, indice) {
                    item.classList.toggle('is-history-hidden', indice >= limite);
                });

                const pie = document.createElement('div');
                pie.className = 'linkage-history-more linkage-activity-more';

                const resumen = document.createElement('span');
                resumen.textContent = 'Mostrando ' + limite + ' de ' + items.length + ' actividades';

                const boton = document.createElement('button');
                boton.type = 'button';
                boton.className = 'btn btn-system-light linkage-activity-more-btn';
                boton.innerHTML =
                    '<i class="bi bi-chevron-down me-1"></i>Ver ' +
                    (items.length - limite) + ' más';

                let expandido = false;
                boton.addEventListener('click', function () {
                    expandido = !expandido;

                    items.forEach(function (item, indice) {
                        item.classList.toggle(
                            'is-history-hidden',
                            !expandido && indice >= limite
                        );
                    });

                    resumen.textContent = expandido
                        ? 'Mostrando las ' + items.length + ' actividades'
                        : 'Mostrando ' + limite + ' de ' + items.length + ' actividades';
                    boton.innerHTML = expandido
                        ? '<i class="bi bi-chevron-up me-1"></i>Ver menos'
                        : '<i class="bi bi-chevron-down me-1"></i>Ver ' +
                            (items.length - limite) + ' más';
                });

                pie.appendChild(resumen);
                pie.appendChild(boton);
                seccionActividad.appendChild(pie);
            }
        }, 0);
    });
})();
