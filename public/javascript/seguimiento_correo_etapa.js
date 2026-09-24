(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        const offcanvas = document.getElementById('offcanvasSeguimientoTrabajo');

        if (!offcanvas || !window.bootstrap) {
            return;
        }

        const urlBorrador = 'index.php?controller=seguimientoFlujo&action=borradorSeguimientoCorreo';
        const urlGuardar = 'index.php?controller=seguimientoFlujo&action=registrarPostEnvio';
        let seguimientoActualId = 0;
        let enviando = false;
        let archivosNuevos = [];

        const seguimientoIdActual = function () {
            const desdeFlujo = Number(offcanvas.dataset.flowSeguimientoId || 0);
            return desdeFlujo > 0 ? desdeFlujo : seguimientoActualId;
        };

        const asegurarModal = function () {
            let modal = document.getElementById('modalSeguimientoCorreo');

            if (modal) {
                return modal;
            }

            modal = document.createElement('div');
            modal.className = 'modal fade';
            modal.id = 'modalSeguimientoCorreo';
            modal.tabIndex = -1;
            modal.setAttribute('aria-labelledby', 'modalSeguimientoCorreoTitulo');
            modal.setAttribute('aria-hidden', 'true');
            modal.innerHTML =
                '<div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">' +
                    '<div class="modal-content system-form-modal">' +
                        '<form enctype="multipart/form-data" data-followup-mail-form>' +
                            '<div class="modal-header system-form-modal-header">' +
                                '<div>' +
                                    '<h5 class="system-form-modal-title" id="modalSeguimientoCorreoTitulo">' +
                                        'Correo de seguimiento' +
                                    '</h5>' +
                                    '<p class="system-form-modal-subtitle">' +
                                        'Revisa el destinatario, asunto y mensaje antes de enviar.' +
                                    '</p>' +
                                '</div>' +
                                '<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>' +
                            '</div>' +
                            '<div class="modal-body">' +
                                '<div class="alert alert-danger d-none mb-3" data-followup-mail-error></div>' +
                                '<div class="followup-mail-history-note d-none" data-followup-mail-info>' +
                                    '<i class="bi bi-clock-history"></i>' +
                                    '<span data-followup-mail-info-text></span>' +
                                '</div>' +
                                '<div class="mb-3">' +
                                    '<label class="form-label" for="followup_mail_to">Para</label>' +
                                    '<input class="form-control" id="followup_mail_to" type="email" readonly data-followup-mail-to>' +
                                '</div>' +
                                '<div class="mb-3">' +
                                    '<label class="form-label" for="followup_mail_subject">Asunto</label>' +
                                    '<input class="form-control" id="followup_mail_subject" name="asunto" maxlength="255" required data-followup-mail-subject>' +
                                '</div>' +
                                '<div class="mb-3">' +
                                    '<label class="form-label" for="followup_mail_body">Mensaje</label>' +
                                    '<textarea class="form-control" id="followup_mail_body" name="cuerpo" rows="9" maxlength="20000" required data-followup-mail-body></textarea>' +
                                '</div>' +
                                '<section class="followup-attachments" data-followup-attachments>' +
                                    '<div class="followup-attachments-head">' +
                                        '<div>' +
                                            '<strong>Adjuntos opcionales</strong>' +
                                            '<span>Agrega documentos solo cuando ayuden a continuar la coordinación.</span>' +
                                        '</div>' +
                                        '<button type="button" class="btn btn-system-light followup-upload-button" data-followup-file-trigger>' +
                                            '<i class="bi bi-paperclip me-2"></i>Subir archivo' +
                                        '</button>' +
                                    '</div>' +
                                    '<div class="d-none" data-followup-expedient-block>' +
                                        '<span class="followup-attachments-label">Desde el expediente</span>' +
                                        '<div class="followup-expedient-list" data-followup-expedient-list></div>' +
                                    '</div>' +
                                    '<input class="d-none" type="file" name="adjuntos_nuevos[]" multiple ' +
                                        'accept=".pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt,.csv,.png,.jpg,.jpeg" ' +
                                        'data-followup-file-input>' +
                                    '<div class="d-none" data-followup-new-block>' +
                                        '<span class="followup-attachments-label">Archivos nuevos</span>' +
                                        '<div class="followup-new-list" data-followup-new-list></div>' +
                                    '</div>' +
                                    '<div class="followup-attachments-empty" data-followup-empty>No hay archivos adjuntos.</div>' +
                                    '<div class="followup-attachments-status" data-followup-attachments-status>0 adjuntos seleccionados</div>' +
                                    '<small class="followup-attachments-help">Hasta 8 archivos · 12 MB por archivo · 20 MB en total.</small>' +
                                '</section>' +
                            '</div>' +
                            '<div class="modal-footer system-form-modal-footer">' +
                                '<span class="me-auto text-muted small">' +
                                    'Cada envío quedará registrado por separado en el historial.' +
                                '</span>' +
                                '<button type="button" class="btn btn-system-cancel" data-bs-dismiss="modal">Cancelar</button>' +
                                '<button type="submit" class="btn btn-system-save" data-followup-mail-send>' +
                                    '<i class="bi bi-send me-2"></i><span data-followup-send-label>Enviar correo</span>' +
                                '</button>' +
                            '</div>' +
                        '</form>' +
                    '</div>' +
                '</div>';

            document.body.appendChild(modal);
            modal.querySelector('[data-followup-mail-form]').addEventListener('submit', enviarCorreo);

            modal.querySelector('[data-followup-file-trigger]').addEventListener('click', function () {
                modal.querySelector('[data-followup-file-input]').click();
            });

            modal.querySelector('[data-followup-file-input]').addEventListener('change', function (event) {
                agregarArchivosNuevos(modal, Array.from(event.target.files || []));
                sincronizarInputArchivos(modal);
            });

            modal.addEventListener('click', function (event) {
                const quitar = event.target.closest('[data-followup-remove-file]');
                if (!quitar) {
                    return;
                }

                const indice = Number(quitar.getAttribute('data-followup-remove-file'));
                if (Number.isInteger(indice) && indice >= 0) {
                    archivosNuevos.splice(indice, 1);
                    sincronizarInputArchivos(modal);
                    renderizarArchivosNuevos(modal);
                }
            });

            modal.addEventListener('change', function (event) {
                if (!event.target.matches('[name="adjuntos_expediente[]"]')) {
                    return;
                }

                if (!limitesAdjuntosValidos(modal)) {
                    event.target.checked = false;
                    mostrarError('Los adjuntos no pueden superar 8 archivos ni 20 MB en total.');
                }
                actualizarResumenAdjuntos(modal);
            });

            return modal;
        };

        const formatearTamano = function (bytes) {
            const valor = Number(bytes || 0);
            if (valor < 1024) {
                return valor + ' B';
            }
            if (valor < 1024 * 1024) {
                return (valor / 1024).toFixed(1) + ' KB';
            }
            return (valor / (1024 * 1024)).toFixed(1) + ' MB';
        };

        const sincronizarInputArchivos = function (modal) {
            const input = modal.querySelector('[data-followup-file-input]');
            if (!input || typeof DataTransfer === 'undefined') {
                return false;
            }

            const transferencia = new DataTransfer();
            archivosNuevos.forEach(function (archivo) {
                transferencia.items.add(archivo);
            });
            input.files = transferencia.files;
            return true;
        };

        const tamanoExpedienteSeleccionado = function (modal) {
            return Array.from(
                modal.querySelectorAll('[name="adjuntos_expediente[]"]:checked')
            ).reduce(function (total, input) {
                return total + Number(input.dataset.size || 0);
            }, 0);
        };

        const limitesAdjuntosValidos = function (modal) {
            const seleccionados = modal.querySelectorAll(
                '[name="adjuntos_expediente[]"]:checked'
            ).length;
            const totalArchivos = seleccionados + archivosNuevos.length;
            const tamanoNuevos = archivosNuevos.reduce(function (total, archivo) {
                return total + Number(archivo.size || 0);
            }, 0);
            const tamanoTotal = tamanoExpedienteSeleccionado(modal) + tamanoNuevos;

            return totalArchivos <= 8 && tamanoTotal <= 20 * 1024 * 1024;
        };

        const actualizarResumenAdjuntos = function (modal) {
            const seleccionados = modal.querySelectorAll(
                '[name="adjuntos_expediente[]"]:checked'
            ).length;
            const total = seleccionados + archivosNuevos.length;
            const vacio = modal.querySelector('[data-followup-empty]');
            const nuevosBloque = modal.querySelector('[data-followup-new-block]');
            const estado = modal.querySelector('[data-followup-attachments-status]');
            const botonEnviar = modal.querySelector('[data-followup-mail-send]');

            nuevosBloque.classList.toggle('d-none', archivosNuevos.length === 0);
            vacio.classList.toggle('d-none', total > 0);

            if (estado) {
                estado.textContent = total === 1
                    ? '1 adjunto seleccionado'
                    : total + ' adjuntos seleccionados';
                estado.classList.toggle('is-active', total > 0);
            }

            if (botonEnviar) {
                const etiqueta = botonEnviar.querySelector('[data-followup-send-label]');
                if (etiqueta) {
                    etiqueta.textContent = total > 0
                        ? 'Enviar correo · ' + total + (total === 1 ? ' adjunto' : ' adjuntos')
                        : 'Enviar correo';
                }
            }
        };

        const renderizarAdjuntosExpediente = function (modal, adjuntos) {
            const bloque = modal.querySelector('[data-followup-expedient-block]');
            const lista = modal.querySelector('[data-followup-expedient-list]');
            lista.innerHTML = '';

            if (!Array.isArray(adjuntos) || adjuntos.length === 0) {
                bloque.classList.add('d-none');
                actualizarResumenAdjuntos(modal);
                return;
            }

            adjuntos.forEach(function (adjunto) {
                const label = document.createElement('label');
                label.className = 'followup-expedient-item';

                const input = document.createElement('input');
                input.type = 'checkbox';
                input.className = 'form-check-input';
                input.name = 'adjuntos_expediente[]';
                input.value = String(adjunto.valor || '');
                input.dataset.size = String(Number(adjunto.tamano || 0));

                const icono = document.createElement('span');
                icono.className = 'followup-attachment-icon';
                icono.innerHTML = '<i class="bi bi-file-earmark"></i>';

                const texto = document.createElement('span');
                texto.className = 'followup-attachment-copy';

                const nombre = document.createElement('strong');
                nombre.textContent = String(adjunto.nombre || 'Archivo');

                const detalle = document.createElement('small');
                const detalleTexto = String(adjunto.detalle || 'Archivo del expediente');
                detalle.textContent = detalleTexto + ' · ' +
                    formatearTamano(adjunto.tamano);

                texto.appendChild(nombre);
                texto.appendChild(detalle);
                label.appendChild(input);
                label.appendChild(icono);
                label.appendChild(texto);
                lista.appendChild(label);
            });

            bloque.classList.remove('d-none');
            actualizarResumenAdjuntos(modal);
        };

        const renderizarArchivosNuevos = function (modal) {
            const lista = modal.querySelector('[data-followup-new-list]');
            lista.innerHTML = '';

            archivosNuevos.forEach(function (archivo, indice) {
                const item = document.createElement('div');
                item.className = 'followup-new-item';

                const icono = document.createElement('span');
                icono.className = 'followup-attachment-icon';
                icono.innerHTML = '<i class="bi bi-paperclip"></i>';

                const texto = document.createElement('span');
                texto.className = 'followup-attachment-copy';

                const nombre = document.createElement('strong');
                nombre.textContent = archivo.name;

                const detalle = document.createElement('small');
                detalle.textContent = 'Archivo nuevo · ' + formatearTamano(archivo.size);

                const quitar = document.createElement('button');
                quitar.type = 'button';
                quitar.className = 'followup-remove-file';
                quitar.setAttribute('data-followup-remove-file', String(indice));
                quitar.setAttribute('aria-label', 'Quitar ' + archivo.name);
                quitar.innerHTML = '<i class="bi bi-x-lg"></i>';

                texto.appendChild(nombre);
                texto.appendChild(detalle);
                item.appendChild(icono);
                item.appendChild(texto);
                item.appendChild(quitar);
                lista.appendChild(item);
            });

            actualizarResumenAdjuntos(modal);
        };

        const agregarArchivosNuevos = function (modal, archivos) {
            const extensiones = new Set([
                'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx',
                'txt', 'csv', 'png', 'jpg', 'jpeg'
            ]);

            for (const archivo of archivos) {
                const extension = String(archivo.name || '')
                    .split('.')
                    .pop()
                    .toLowerCase();

                if (!extensiones.has(extension)) {
                    mostrarError('El archivo "' + archivo.name + '" tiene un formato no permitido.');
                    continue;
                }
                if (Number(archivo.size || 0) <= 0 || archivo.size > 12 * 1024 * 1024) {
                    mostrarError('El archivo "' + archivo.name + '" debe pesar como máximo 12 MB.');
                    continue;
                }

                const duplicado = archivosNuevos.some(function (actual) {
                    return actual.name === archivo.name &&
                        actual.size === archivo.size &&
                        actual.lastModified === archivo.lastModified;
                });
                if (!duplicado) {
                    archivosNuevos.push(archivo);
                }

                if (!limitesAdjuntosValidos(modal)) {
                    archivosNuevos.pop();
                    mostrarError('Los adjuntos no pueden superar 8 archivos ni 20 MB en total.');
                    break;
                }
            }

            renderizarArchivosNuevos(modal);
        };

        const mostrarError = function (mensaje) {
            const modal = asegurarModal();
            const error = modal.querySelector('[data-followup-mail-error]');
            error.textContent = String(mensaje || 'No fue posible preparar el correo.');
            error.classList.remove('d-none');
        };

        const limpiarMensajes = function () {
            const modal = asegurarModal();
            const error = modal.querySelector('[data-followup-mail-error]');
            const info = modal.querySelector('[data-followup-mail-info]');
            error.classList.add('d-none');
            error.textContent = '';
            info.classList.add('d-none');
            const infoTexto = info.querySelector('[data-followup-mail-info-text]');
            if (infoTexto) {
                infoTexto.textContent = '';
            }
        };

        const mostrarToast = function (mensaje) {
            let contenedor = document.querySelector('.toast-container');

            if (!contenedor) {
                contenedor = document.createElement('div');
                contenedor.className = 'toast-container position-fixed top-0 end-0 p-3';
                document.body.appendChild(contenedor);
            }

            const toast = document.createElement('div');
            toast.className = 'toast system-toast';
            toast.setAttribute('role', 'status');
            toast.innerHTML =
                '<div class="toast-body">' +
                    '<i class="bi bi-check-circle"></i>' +
                    '<span></span>' +
                '</div>';
            toast.querySelector('span').textContent = String(mensaje || 'Correo enviado correctamente.');
            contenedor.appendChild(toast);
            toast.addEventListener('hidden.bs.toast', function () {
                toast.remove();
            });
            bootstrap.Toast.getOrCreateInstance(toast, { autohide: true, delay: 3500 }).show();
        };

        const abrirCorreo = async function () {
            const seguimientoId = seguimientoIdActual();

            if (seguimientoId <= 0) {
                return;
            }

            const modal = asegurarModal();
            const para = modal.querySelector('[data-followup-mail-to]');
            const asunto = modal.querySelector('[data-followup-mail-subject]');
            const cuerpo = modal.querySelector('[data-followup-mail-body]');
            const enviar = modal.querySelector('[data-followup-mail-send]');
            const info = modal.querySelector('[data-followup-mail-info]');

            limpiarMensajes();
            archivosNuevos = [];
            const inputArchivos = modal.querySelector('[data-followup-file-input]');
            if (inputArchivos) {
                inputArchivos.value = '';
            }
            para.value = 'Consultando...';
            asunto.value = '';
            cuerpo.value = '';
            renderizarArchivosNuevos(modal);
            renderizarAdjuntosExpediente(modal, []);
            enviar.disabled = true;
            bootstrap.Modal.getOrCreateInstance(modal).show();

            try {
                const respuesta = await fetch(
                    urlBorrador + '&seguimiento_id=' + encodeURIComponent(seguimientoId),
                    {
                        headers: { 'X-Requested-With': 'fetch' },
                        cache: 'no-store'
                    }
                );
                const json = await respuesta.json();

                if (!respuesta.ok || !json.ok || !json.correo) {
                    mostrarError(json.mensaje || 'No fue posible preparar el correo de seguimiento.');
                    para.value = '';
                    return;
                }

                para.value = String(json.correo.para || '');
                asunto.value = String(json.correo.asunto || '');
                cuerpo.value = String(json.correo.cuerpo || '');
                renderizarAdjuntosExpediente(
                    modal,
                    json.correo.adjuntos_disponibles || []
                );

                const total = Number(json.correo.total_enviados || 0);
                if (total > 0) {
                    const infoTexto = info.querySelector('[data-followup-mail-info-text]');
                    if (infoTexto) {
                        infoTexto.textContent = total === 1
                            ? '1 correo de seguimiento enviado anteriormente. Puedes enviar otro si aún necesitan coordinar detalles.'
                            : total + ' correos de seguimiento enviados anteriormente. Puedes continuar la coordinación si hace falta.';
                    }
                    info.classList.remove('d-none');
                }
            } catch (error) {
                console.error(error);
                mostrarError('No fue posible comunicarse con el sistema.');
                para.value = '';
            } finally {
                enviar.disabled = false;
            }
        };

        async function enviarCorreo(event) {
            event.preventDefault();

            if (enviando) {
                return;
            }

            const seguimientoId = seguimientoIdActual();
            const modal = asegurarModal();
            const boton = modal.querySelector('[data-followup-mail-send]');
            const form = event.currentTarget;
            const datos = new FormData(form);
            const adjuntosExpediente = Array.from(
                modal.querySelectorAll('[name="adjuntos_expediente[]"]:checked')
            );

            if (!limitesAdjuntosValidos(modal)) {
                mostrarError('Los adjuntos no pueden superar 8 archivos ni 20 MB en total.');
                return;
            }

            // Los checkboxes y el input file ahora son campos reales del
            // formulario multipart. Solo usamos append manual como respaldo en
            // navegadores sin DataTransfer.
            datos.delete('adjuntos_expediente[]');
            adjuntosExpediente.forEach(function (input) {
                datos.append('adjuntos_expediente[]', String(input.value || ''));
            });

            const inputArchivos = modal.querySelector('[data-followup-file-input]');
            const archivosInput = Array.from(inputArchivos?.files || []);

            if (archivosInput.length !== archivosNuevos.length) {
                datos.delete('adjuntos_nuevos[]');
                archivosNuevos.forEach(function (archivo) {
                    datos.append('adjuntos_nuevos[]', archivo, archivo.name);
                });
            }

            const totalEsperado = adjuntosExpediente.length + archivosNuevos.length;
            datos.set('adjuntos_esperados', String(totalEsperado));
            datos.set('adjuntos_expediente_esperados', String(adjuntosExpediente.length));
            datos.set('adjuntos_nuevos_esperados', String(archivosNuevos.length));

            if (seguimientoId <= 0) {
                mostrarError('No se pudo identificar el seguimiento.');
                return;
            }

            datos.set('seguimiento_id', String(seguimientoId));
            datos.set('accion', 'ENVIAR_SEGUIMIENTO_CORREO');
            enviando = true;
            boton.disabled = true;
            limpiarMensajes();

            try {
                const respuesta = await fetch(urlGuardar, {
                    method: 'POST',
                    body: datos,
                    headers: { 'X-Requested-With': 'fetch' },
                    credentials: 'same-origin'
                });
                const json = await respuesta.json();

                if (!respuesta.ok || !json.ok) {
                    mostrarError(json.mensaje || 'No fue posible enviar el correo de seguimiento.');
                    return;
                }

                bootstrap.Modal.getOrCreateInstance(modal).hide();
                const adjuntosConfirmados = Number(json.adjuntos_enviados || 0);
                mostrarToast(
                    adjuntosConfirmados > 0
                        ? 'Correo enviado correctamente con ' +
                            adjuntosConfirmados +
                            (adjuntosConfirmados === 1 ? ' adjunto.' : ' adjuntos.')
                        : (json.mensaje || 'Correo de seguimiento enviado correctamente.')
                );

                const proxima = offcanvas.querySelector('[data-work-next-action]');
                if (proxima) {
                    proxima.textContent = 'Actualizando ruta...';
                }

                document.dispatchEvent(new CustomEvent('impe:post-envio-updated', {
                    detail: {
                        seguimientoId: seguimientoId,
                        accion: 'ENVIAR_SEGUIMIENTO_CORREO'
                    }
                }));
            } catch (error) {
                console.error(error);
                mostrarError('No fue posible comunicarse con el sistema.');
            } finally {
                enviando = false;
                boton.disabled = false;
            }
        }

        const continuarReunion = async function () {
            const seguimientoId = seguimientoIdActual();

            if (seguimientoId <= 0 || enviando) {
                return;
            }

            const datos = new FormData();
            datos.set('seguimiento_id', String(seguimientoId));
            datos.set('accion', 'CONTINUAR_REUNION');
            enviando = true;

            try {
                const respuesta = await fetch(urlGuardar, {
                    method: 'POST',
                    body: datos,
                    headers: { 'X-Requested-With': 'fetch' }
                });
                const json = await respuesta.json();

                if (!respuesta.ok || !json.ok) {
                    mostrarToast(json.mensaje || 'No fue posible continuar a la reunión.');
                    return;
                }

                window.location.href = String(
                    json.url ||
                    ('index.php?controller=agendaReunion&action=index&seguimiento_id=' + seguimientoId)
                );
            } catch (error) {
                console.error(error);
            } finally {
                enviando = false;
            }
        };

        document.addEventListener('click', function (event) {
            const botonTrabajo = event.target.closest('[data-work-follow]');

            if (botonTrabajo) {
                seguimientoActualId = Number(
                    botonTrabajo.getAttribute('data-work-follow-id') || 0
                );
                return;
            }

            const botonFlujo = event.target.closest('[data-flow-action]');
            if (!botonFlujo || !botonFlujo.closest('[data-work-flow-section]')) {
                return;
            }

            const codigo = String(botonFlujo.getAttribute('data-flow-action') || '');

            if (codigo === 'ENVIAR_SEGUIMIENTO_CORREO') {
                event.preventDefault();
                event.stopImmediatePropagation();
                abrirCorreo();
                return;
            }

            if (codigo === 'CONTINUAR_REUNION') {
                event.preventDefault();
                event.stopImmediatePropagation();
                continuarReunion();
                return;
            }

            /*
             * Cuando ya existe una solicitud de reunión, el flujo utiliza el
             * código AGENDAR_REUNION para volver a la Agenda. Lo interceptamos
             * antes del formulario antiguo de post-envío.
             */
            if (codigo === 'AGENDAR_REUNION') {
                const seguimientoId = seguimientoIdActual();
                if (seguimientoId > 0) {
                    event.preventDefault();
                    event.stopImmediatePropagation();
                    window.location.href =
                        'index.php?controller=agendaReunion&action=index&seguimiento_id=' +
                        encodeURIComponent(seguimientoId);
                }
            }
        }, true);

        offcanvas.addEventListener('hidden.bs.offcanvas', function () {
            seguimientoActualId = 0;
        });
    });
})();
