(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        const parametros = new URLSearchParams(window.location.search);
        const esExpediente =
            parametros.get('controller') === 'seguimientoVinculacion' &&
            parametros.get('action') === 'detalle';
        const seguimientoId = Number(parametros.get('id') || 0);

        if (!esExpediente || seguimientoId <= 0) {
            return;
        }

        const meses = [
            'ene', 'feb', 'mar', 'abr', 'may', 'jun',
            'jul', 'ago', 'sep', 'oct', 'nov', 'dic'
        ];
        let correosActuales = [];
        let intentos = 0;

        const fechaLegible = function (valor) {
            const texto = String(valor || '').trim();
            const coincidencia = texto.match(
                /^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2}))?/
            );

            if (!coincidencia) {
                return texto || '—';
            }

            const indiceMes = Math.max(0, Math.min(11, Number(coincidencia[2]) - 1));
            let salida =
                coincidencia[3] + ' ' +
                meses[indiceMes] + ' ' +
                coincidencia[1];

            if (coincidencia[4]) {
                salida += ' · ' + coincidencia[4] + ':' + coincidencia[5];
            }

            return salida;
        };

        const tamanoLegible = function (bytes) {
            const total = Number(bytes || 0);
            if (!Number.isFinite(total) || total <= 0) {
                return '';
            }
            if (total < 1024 * 1024) {
                return Math.max(1, Math.round(total / 1024)) + ' KB';
            }
            return (total / (1024 * 1024)).toFixed(1) + ' MB';
        };

        const crearModal = function () {
            let modal = document.getElementById('modalDetalleCorreoExpediente');

            if (modal) {
                return modal;
            }

            modal = document.createElement('div');
            modal.className = 'modal fade linkage-mail-detail-modal';
            modal.id = 'modalDetalleCorreoExpediente';
            modal.tabIndex = -1;
            modal.setAttribute('aria-hidden', 'true');
            modal.innerHTML =
                '<div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">' +
                    '<div class="modal-content">' +
                        '<div class="modal-header">' +
                            '<div>' +
                                '<span class="linkage-mail-modal-eyebrow">CORREO REGISTRADO</span>' +
                                '<h2 class="modal-title fs-5">Detalle del correo</h2>' +
                            '</div>' +
                            '<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>' +
                        '</div>' +
                        '<div class="modal-body">' +
                            '<div class="linkage-mail-modal-meta">' +
                                '<div><span>Destinatario</span><strong data-mail-history-to>—</strong></div>' +
                                '<div><span>Fecha de envío</span><strong data-mail-history-date>—</strong></div>' +
                                '<div><span>Enviado por</span><strong data-mail-history-user>—</strong></div>' +
                                '<div><span>Adjuntos</span><strong data-mail-history-attachment>—</strong></div>' +
                            '</div>' +
                            '<div class="linkage-mail-modal-field">' +
                                '<span>Asunto</span>' +
                                '<strong data-mail-history-subject>—</strong>' +
                            '</div>' +
                            '<div class="linkage-mail-modal-field is-body">' +
                                '<span>Mensaje</span>' +
                                '<div data-mail-history-body>—</div>' +
                            '</div>' +
                            '<div class="linkage-mail-modal-attachments d-none" data-mail-history-attachments-block>' +
                                '<span>Archivos adjuntos</span>' +
                                '<div data-mail-history-attachments-list></div>' +
                            '</div>' +
                        '</div>' +
                        '<div class="modal-footer">' +
                            '<button type="button" class="btn btn-system-cancel" data-bs-dismiss="modal">Cerrar</button>' +
                        '</div>' +
                    '</div>' +
                '</div>';
            document.body.appendChild(modal);

            return modal;
        };

        const renderizarAdjuntosModal = function (modal, adjuntos) {
            const bloque = modal.querySelector('[data-mail-history-attachments-block]');
            const lista = modal.querySelector('[data-mail-history-attachments-list]');
            const resumen = modal.querySelector('[data-mail-history-attachment]');
            const archivos = Array.isArray(adjuntos) ? adjuntos : [];

            lista.innerHTML = '';

            if (archivos.length === 0) {
                bloque.classList.add('d-none');
                resumen.textContent = 'Sin adjuntos';
                return;
            }

            resumen.textContent = archivos.length === 1
                ? '1 archivo'
                : archivos.length + ' archivos';

            archivos.forEach(function (archivo) {
                const enlace = document.createElement('a');
                enlace.className = 'linkage-mail-modal-attachment';
                enlace.href = String(archivo.url || '#');
                enlace.innerHTML =
                    '<i class="bi bi-paperclip"></i>' +
                    '<span><strong></strong><small></small></span>' +
                    '<i class="bi bi-download"></i>';

                enlace.querySelector('strong').textContent =
                    String(archivo.nombre || 'Archivo adjunto');
                enlace.querySelector('small').textContent =
                    archivo.tamano
                        ? tamanoLegible(archivo.tamano)
                        : 'Descargar';

                lista.appendChild(enlace);
            });

            bloque.classList.remove('d-none');
        };

        const abrirDetalle = function (correo) {
            const modal = crearModal();
            const destino = [
                String(correo.destinatario_nombre || '').trim(),
                String(correo.destinatario || '').trim()
            ].filter(Boolean).join(' · ');

            modal.querySelector('[data-mail-history-to]').textContent = destino || '—';
            modal.querySelector('[data-mail-history-date]').textContent =
                fechaLegible(correo.fecha_envio);
            modal.querySelector('[data-mail-history-user]').textContent =
                String(correo.enviado_por || '').trim() || 'Sistema';
            const adjuntoPrimario = String(correo.adjunto_nombre || '').trim();
            renderizarAdjuntosModal(
                modal,
                adjuntoPrimario
                    ? [{
                        nombre: adjuntoPrimario,
                        tamano: Number(correo.adjunto_tamano || 0),
                        url: String(correo.adjunto_url || '#')
                    }]
                    : []
            );
            modal.querySelector('[data-mail-history-subject]').textContent =
                String(correo.asunto || '').trim() || 'Sin asunto';
            modal.querySelector('[data-mail-history-body]').textContent =
                String(correo.cuerpo || '').trim() || 'Sin contenido registrado.';

            bootstrap.Modal.getOrCreateInstance(modal).show();
        };

        const abrirCorreoSeguimiento = async function (interaccionId) {
            const modal = crearModal();

            try {
                const respuesta = await fetch(
                    'index.php?controller=seguimientoVinculacion&action=verCorreoSeguimiento&interaccion_id=' +
                    encodeURIComponent(interaccionId),
                    {
                        headers: { 'X-Requested-With': 'fetch' },
                        cache: 'no-store'
                    }
                );
                const datos = await respuesta.json();

                if (!respuesta.ok || !datos.ok || !datos.correo) {
                    throw new Error(datos.mensaje || 'No fue posible consultar el correo.');
                }

                const correo = datos.correo;
                modal.querySelector('.linkage-mail-modal-eyebrow').textContent =
                    'CORREO DE SEGUIMIENTO';
                modal.querySelector('.modal-title').textContent =
                    'Correo enviado';
                modal.querySelector('[data-mail-history-to]').textContent =
                    String(correo.destinatario || '').trim() || '—';
                modal.querySelector('[data-mail-history-date]').textContent =
                    fechaLegible(correo.enviado_at);
                modal.querySelector('[data-mail-history-user]').textContent =
                    String(correo.proveedor || '').trim() || 'Sistema';
                modal.querySelector('[data-mail-history-subject]').textContent =
                    String(correo.asunto || '').trim() || 'Sin asunto';
                modal.querySelector('[data-mail-history-body]').textContent =
                    String(correo.cuerpo || '').trim() ||
                    'El contenido completo no quedó disponible para este correo histórico.';

                renderizarAdjuntosModal(modal, correo.adjuntos || []);
                bootstrap.Modal.getOrCreateInstance(modal).show();
            } catch (error) {
                console.error(error);
            }
        };

        const renderizar = function (seccion, correos) {
            correosActuales = Array.isArray(correos) ? correos : [];
            const contador = seccion.querySelector('[data-mail-history-count]');
            const cuerpo = seccion.querySelector('[data-mail-history-content]');

            if (!contador || !cuerpo) {
                return;
            }

            contador.textContent = correosActuales.length === 1
                ? '1 correo'
                : correosActuales.length + ' correos';

            if (correosActuales.length === 0) {
                cuerpo.innerHTML =
                    '<div class="linkage-mail-history-empty">' +
                        '<span><i class="bi bi-envelope"></i></span>' +
                        '<div><strong>Aún no hay correos registrados</strong>' +
                        '<p>Cuando envíes un correo relacionado con este oficio, aparecerá aquí.</p></div>' +
                    '</div>';
                return;
            }

            const tabla = document.createElement('div');
            tabla.className = 'table-responsive linkage-mail-history-table-wrap';
            tabla.innerHTML =
                '<table class="table align-middle linkage-mail-history-table">' +
                    '<thead><tr>' +
                        '<th>Fecha</th>' +
                        '<th>Destinatario</th>' +
                        '<th>Asunto</th>' +
                        '<th>Estado</th>' +
                        '<th>Adjunto</th>' +
                        '<th class="text-end">Acción</th>' +
                    '</tr></thead>' +
                    '<tbody></tbody>' +
                '</table>';

            const tbody = tabla.querySelector('tbody');

            correosActuales.forEach(function (correo, indice) {
                const fila = document.createElement('tr');
                const estado = String(correo.estado || 'ENVIADO').toUpperCase();
                const nombreDestinatario = String(correo.destinatario_nombre || '').trim();
                const correoDestinatario = String(correo.destinatario || '').trim();
                const adjunto = String(correo.adjunto_nombre || '').trim();

                const celdaFecha = document.createElement('td');
                celdaFecha.innerHTML = '<strong></strong><span></span>';
                celdaFecha.querySelector('strong').textContent = fechaLegible(correo.fecha_envio);
                celdaFecha.querySelector('span').textContent =
                    String(correo.enviado_por || '').trim() || 'Sistema';

                const celdaDestinatario = document.createElement('td');
                celdaDestinatario.innerHTML = '<strong></strong><span></span>';
                celdaDestinatario.querySelector('strong').textContent =
                    nombreDestinatario || correoDestinatario || '—';
                celdaDestinatario.querySelector('span').textContent =
                    nombreDestinatario && correoDestinatario ? correoDestinatario : '';

                const celdaAsunto = document.createElement('td');
                celdaAsunto.className = 'linkage-mail-history-subject';
                celdaAsunto.textContent = String(correo.asunto || '').trim() || 'Sin asunto';

                const celdaEstado = document.createElement('td');
                const badge = document.createElement('span');
                badge.className = 'linkage-mail-history-status' +
                    (estado === 'ENVIADO' ? ' is-sent' : ' is-error');
                badge.innerHTML = estado === 'ENVIADO'
                    ? '<i class="bi bi-check2-circle"></i><span>Enviado</span>'
                    : '<i class="bi bi-exclamation-circle"></i><span>Error</span>';
                celdaEstado.appendChild(badge);

                const celdaAdjunto = document.createElement('td');
                celdaAdjunto.className = 'linkage-mail-history-attachment';
                if (adjunto) {
                    celdaAdjunto.innerHTML = '<i class="bi bi-paperclip"></i><span></span>';
                    celdaAdjunto.querySelector('span').textContent = adjunto;
                } else {
                    celdaAdjunto.textContent = '—';
                }

                const celdaAccion = document.createElement('td');
                celdaAccion.className = 'text-end';
                const boton = document.createElement('button');
                boton.type = 'button';
                boton.className = 'btn btn-system-light linkage-mail-history-view';
                boton.dataset.mailHistoryIndex = String(indice);
                boton.innerHTML = '<i class="bi bi-eye"></i><span>Ver correo</span>';
                celdaAccion.appendChild(boton);

                fila.appendChild(celdaFecha);
                fila.appendChild(celdaDestinatario);
                fila.appendChild(celdaAsunto);
                fila.appendChild(celdaEstado);
                fila.appendChild(celdaAdjunto);
                fila.appendChild(celdaAccion);
                tbody.appendChild(fila);
            });

            cuerpo.replaceChildren(tabla);
        };

        const cargarHistorial = async function (seccion) {
            const cuerpo = seccion.querySelector('[data-mail-history-content]');

            try {
                const respuesta = await fetch(
                    'index.php?controller=oficioCorreo&action=historial&seguimiento_id=' +
                    encodeURIComponent(seguimientoId),
                    {
                        headers: { 'X-Requested-With': 'fetch' },
                        cache: 'no-store'
                    }
                );
                const datos = await respuesta.json();

                if (!respuesta.ok || !datos.ok) {
                    throw new Error(datos.mensaje || 'No fue posible consultar los correos.');
                }

                renderizar(seccion, datos.correos || []);
            } catch (error) {
                if (cuerpo) {
                    cuerpo.innerHTML =
                        '<div class="linkage-mail-history-error">' +
                            '<i class="bi bi-exclamation-circle"></i>' +
                            '<span>No fue posible cargar el historial de correos.</span>' +
                        '</div>';
                }
                console.error(error);
            }
        };

        const crearSeccion = function (pane) {
            let seccion = pane.querySelector('[data-mail-history-section]');

            if (seccion) {
                return seccion;
            }

            seccion = document.createElement('section');
            seccion.className =
                'dashboard-panel linkage-detail-panel linkage-mail-history-section';
            seccion.setAttribute('data-mail-history-section', '');
            seccion.innerHTML =
                '<div class="linkage-mail-history-heading">' +
                    '<div>' +
                        '<span class="linkage-expediente-eyebrow">COMUNICACIÓN</span>' +
                        '<h3>Correos relacionados</h3>' +
                        '<p>Historial de correos enviados desde este expediente.</p>' +
                    '</div>' +
                    '<span class="linkage-mail-history-count" data-mail-history-count>0 correos</span>' +
                '</div>' +
                '<div data-mail-history-content>' +
                    '<div class="linkage-mail-history-loading">' +
                        '<span class="spinner-border spinner-border-sm" aria-hidden="true"></span>' +
                        '<span>Cargando correos…</span>' +
                    '</div>' +
                '</div>';
            pane.appendChild(seccion);
            return seccion;
        };

        const inicializar = function () {
            const pane = document.querySelector(
                '.linkage-expediente-pane[data-expediente-pane="oficios"]'
            );

            if (!pane) {
                if (intentos < 30) {
                    intentos++;
                    window.setTimeout(inicializar, 60);
                }
                return;
            }

            const seccion = crearSeccion(pane);
            cargarHistorial(seccion);
        };

        document.addEventListener('click', function (event) {
            const botonSeguimiento = event.target.closest('[data-followup-mail-interaction]');

            if (botonSeguimiento) {
                event.preventDefault();
                const interaccionId = Number(
                    botonSeguimiento.getAttribute('data-followup-mail-interaction') || 0
                );
                if (interaccionId > 0) {
                    abrirCorreoSeguimiento(interaccionId);
                }
                return;
            }

            const boton = event.target.closest('[data-mail-history-index]');

            if (!boton) {
                return;
            }

            const indice = Number(boton.dataset.mailHistoryIndex || -1);
            const correo = correosActuales[indice];

            if (!correo) {
                return;
            }

            event.preventDefault();
            abrirDetalle(correo);
        });

        inicializar();
    });
})();
