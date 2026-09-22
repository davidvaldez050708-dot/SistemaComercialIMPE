(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        const offcanvas = document.getElementById('offcanvasSeguimientoTrabajo');

        if (!offcanvas || !window.bootstrap) {
            return;
        }

        const urlBorrador =
            'index.php?controller=seguimientoVinculacion&action=borradorCorreoTrabajo';
        const urlEnviar =
            'index.php?controller=seguimientoVinculacion&action=enviarCorreoTrabajo';
        let enviando = false;

        const escapar = function (valor) {
            const div = document.createElement('div');
            div.textContent = String(valor || '');
            return div.innerHTML;
        };

        const seguimientoIdActual = function () {
            const desdeRuta = Number(offcanvas.dataset.flowSeguimientoId || 0);
            if (desdeRuta > 0) {
                return desdeRuta;
            }

            return Number(
                offcanvas.querySelector('[data-work-interaction-id]')?.value ||
                offcanvas.querySelector('[data-work-contact-id]')?.value ||
                0
            );
        };

        const asegurarModal = function () {
            let modal = document.getElementById('modalCorreoContacto');

            if (modal) {
                return modal;
            }

            modal = document.createElement('div');
            modal.className = 'modal fade';
            modal.id = 'modalCorreoContacto';
            modal.tabIndex = -1;
            modal.setAttribute('aria-labelledby', 'modalCorreoContactoTitulo');
            modal.setAttribute('aria-hidden', 'true');
            modal.innerHTML =
                '<div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">' +
                    '<div class="modal-content system-form-modal">' +
                        '<form data-contact-mail-form enctype="multipart/form-data">' +
                            '<div class="modal-header system-form-modal-header">' +
                                '<div>' +
                                    '<h5 class="system-form-modal-title" id="modalCorreoContactoTitulo">' +
                                        'Enviar correo' +
                                    '</h5>' +
                                    '<p class="system-form-modal-subtitle">' +
                                        'Redacta el mensaje y, si lo necesitas, adjunta documentos.' +
                                    '</p>' +
                                '</div>' +
                                '<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>' +
                            '</div>' +
                            '<div class="modal-body">' +
                                '<div class="alert alert-danger d-none mb-3" data-contact-mail-error></div>' +
                                '<div class="contact-mail-summary mb-3">' +
                                    '<span>DESTINATARIO</span>' +
                                    '<strong data-contact-mail-recipient>—</strong>' +
                                '</div>' +
                                '<div class="mb-3">' +
                                    '<label class="form-label" for="contact_mail_to">Para</label>' +
                                    '<input class="form-control" id="contact_mail_to" type="email" readonly data-contact-mail-to>' +
                                '</div>' +
                                '<div class="mb-3">' +
                                    '<label class="form-label" for="contact_mail_subject">Asunto</label>' +
                                    '<input class="form-control" id="contact_mail_subject" name="asunto" maxlength="255" required data-contact-mail-subject>' +
                                '</div>' +
                                '<div class="mb-3">' +
                                    '<label class="form-label" for="contact_mail_body">Mensaje</label>' +
                                    '<textarea class="form-control" id="contact_mail_body" name="cuerpo" rows="9" maxlength="20000" required data-contact-mail-body></textarea>' +
                                '</div>' +
                                '<div class="mb-0">' +
                                    '<label class="form-label" for="contact_mail_files">Adjuntos <span class="text-muted">(opcional)</span></label>' +
                                    '<input class="form-control" id="contact_mail_files" type="file" name="adjuntos[]" multiple ' +
                                        'accept=".pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt,.csv,.png,.jpg,.jpeg" ' +
                                        'data-contact-mail-files>' +
                                    '<div class="form-text">' +
                                        'PDF, Office, TXT, CSV, PNG o JPG. Máximo 8 archivos, 12 MB por archivo y 20 MB en total.' +
                                    '</div>' +
                                    '<div class="contact-mail-files d-none" data-contact-mail-files-list></div>' +
                                '</div>' +
                            '</div>' +
                            '<div class="modal-footer system-form-modal-footer">' +
                                '<span class="me-auto text-muted small">' +
                                    'El envío quedará registrado en el historial del expediente.' +
                                '</span>' +
                                '<button type="button" class="btn btn-system-cancel" data-bs-dismiss="modal">' +
                                    'Cancelar' +
                                '</button>' +
                                '<button type="submit" class="btn btn-system-save" data-contact-mail-send>' +
                                    '<i class="bi bi-send me-2"></i>Enviar correo' +
                                '</button>' +
                            '</div>' +
                        '</form>' +
                    '</div>' +
                '</div>';

            document.body.appendChild(modal);

            const form = modal.querySelector('[data-contact-mail-form]');
            const files = modal.querySelector('[data-contact-mail-files]');
            form?.addEventListener('submit', enviarCorreo);
            files?.addEventListener('change', renderizarArchivos);

            return modal;
        };

        const mostrarError = function (mensaje) {
            const modal = asegurarModal();
            const error = modal.querySelector('[data-contact-mail-error]');
            error.textContent = String(mensaje || 'No fue posible enviar el correo.');
            error.classList.remove('d-none');
        };

        const limpiarError = function () {
            const error = asegurarModal().querySelector('[data-contact-mail-error]');
            error.textContent = '';
            error.classList.add('d-none');
        };

        const mostrarToast = function (mensaje, esError) {
            let contenedor = document.querySelector('.toast-container');

            if (!contenedor) {
                contenedor = document.createElement('div');
                contenedor.className = 'toast-container position-fixed top-0 end-0 p-3';
                document.body.appendChild(contenedor);
            }

            const toast = document.createElement('div');
            toast.className = 'toast system-toast' +
                (esError ? ' system-toast-error' : '');
            toast.setAttribute('role', esError ? 'alert' : 'status');
            toast.innerHTML =
                '<div class="toast-body">' +
                    '<i class="bi ' +
                        (esError ? 'bi-exclamation-circle' : 'bi-check2-circle') +
                    '"></i>' +
                    '<span></span>' +
                '</div>';
            toast.querySelector('span').textContent = String(
                mensaje || (esError ? 'No fue posible enviar el correo.' : 'Correo enviado.')
            );
            contenedor.appendChild(toast);
            toast.addEventListener('hidden.bs.toast', function () {
                toast.remove();
            });
            bootstrap.Toast.getOrCreateInstance(toast, {
                autohide: true,
                delay: esError ? 4500 : 3500
            }).show();
        };

        function renderizarArchivos() {
            const modal = asegurarModal();
            const input = modal.querySelector('[data-contact-mail-files]');
            const lista = modal.querySelector('[data-contact-mail-files-list]');
            const archivos = Array.from(input?.files || []);

            if (!lista) {
                return;
            }

            lista.classList.toggle('d-none', archivos.length === 0);
            lista.innerHTML = archivos.length === 0
                ? ''
                : archivos.map(function (archivo) {
                    const mb = archivo.size / (1024 * 1024);
                    const tamano = mb >= 1
                        ? mb.toFixed(1) + ' MB'
                        : Math.max(1, Math.round(archivo.size / 1024)) + ' KB';

                    return '<span><i class="bi bi-paperclip"></i>' +
                        escapar(archivo.name) +
                        '<small>' + escapar(tamano) + '</small></span>';
                }).join('');
        }

        const renderizarHistorial = function (interacciones) {
            const lista = offcanvas.querySelector('[data-work-activity-list]');

            if (!lista || !Array.isArray(interacciones)) {
                return;
            }

            if (interacciones.length === 0) {
                lista.innerHTML =
                    '<span class="linkage-work-empty">Sin interacciones registradas.</span>';
                return;
            }

            lista.innerHTML = interacciones.map(function (interaccion) {
                return '<article>' +
                    '<strong>' + escapar(interaccion.fecha_label) + '</strong>' +
                    '<span>' + escapar(interaccion.canal_label) + ' · ' +
                        escapar(interaccion.resultado_label) + '</span>' +
                    (interaccion.notas
                        ? '<p>' + escapar(interaccion.notas) + '</p>'
                        : '') +
                '</article>';
            }).join('');
        };

        const abrirCorreo = async function () {
            const seguimientoId = seguimientoIdActual();

            if (seguimientoId <= 0) {
                mostrarToast(
                    'No fue posible identificar el seguimiento.',
                    true
                );
                return;
            }

            const modal = asegurarModal();
            const para = modal.querySelector('[data-contact-mail-to]');
            const destinatario = modal.querySelector('[data-contact-mail-recipient]');
            const asunto = modal.querySelector('[data-contact-mail-subject]');
            const cuerpo = modal.querySelector('[data-contact-mail-body]');
            const archivos = modal.querySelector('[data-contact-mail-files]');
            const boton = modal.querySelector('[data-contact-mail-send]');

            limpiarError();
            para.value = 'Consultando...';
            destinatario.textContent = 'Consultando...';
            asunto.value = '';
            cuerpo.value = '';
            archivos.value = '';
            renderizarArchivos();
            boton.disabled = true;
            bootstrap.Modal.getOrCreateInstance(modal).show();

            try {
                const respuesta = await fetch(
                    urlBorrador + '&id=' + encodeURIComponent(seguimientoId),
                    {
                        headers: { 'X-Requested-With': 'fetch' },
                        cache: 'no-store'
                    }
                );
                const json = await respuesta.json();

                if (!respuesta.ok || !json.ok || !json.correo) {
                    mostrarError(
                        json.mensaje || 'No fue posible preparar el correo.'
                    );
                    para.value = '';
                    destinatario.textContent = '—';
                    return;
                }

                para.value = String(json.correo.para || '');
                destinatario.textContent = String(
                    json.correo.destinatario_nombre ||
                    json.correo.para ||
                    'Contacto institucional'
                );
                asunto.value = String(json.correo.asunto || '');
                cuerpo.value = String(json.correo.cuerpo || '');
            } catch (error) {
                console.error(error);
                mostrarError('No fue posible comunicarse con el sistema.');
                para.value = '';
                destinatario.textContent = '—';
            } finally {
                boton.disabled = false;
            }
        };

        async function enviarCorreo(event) {
            event.preventDefault();

            if (enviando) {
                return;
            }

            const seguimientoId = seguimientoIdActual();
            const modal = asegurarModal();
            const boton = modal.querySelector('[data-contact-mail-send]');
            const archivos = Array.from(
                modal.querySelector('[data-contact-mail-files]')?.files || []
            );

            if (seguimientoId <= 0) {
                mostrarError('No fue posible identificar el seguimiento.');
                return;
            }

            if (archivos.length > 8) {
                mostrarError('Puedes adjuntar como máximo 8 archivos.');
                return;
            }

            const total = archivos.reduce(function (suma, archivo) {
                return suma + Number(archivo.size || 0);
            }, 0);

            if (archivos.some(function (archivo) {
                return Number(archivo.size || 0) > 12 * 1024 * 1024;
            })) {
                mostrarError('Cada archivo debe pesar como máximo 12 MB.');
                return;
            }

            if (total > 20 * 1024 * 1024) {
                mostrarError('Los archivos adjuntos no pueden superar 20 MB en total.');
                return;
            }

            const datos = new FormData(event.currentTarget);
            datos.set('seguimiento_id', String(seguimientoId));
            const htmlOriginal = boton.innerHTML;

            enviando = true;
            boton.disabled = true;
            boton.innerHTML =
                '<span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>' +
                'Enviando...';
            limpiarError();

            try {
                const respuesta = await fetch(urlEnviar, {
                    method: 'POST',
                    body: datos,
                    headers: { 'X-Requested-With': 'fetch' }
                });
                const json = await respuesta.json();

                if (!respuesta.ok || !json.ok) {
                    mostrarError(
                        json.mensaje || 'No fue posible enviar el correo.'
                    );
                    return;
                }

                bootstrap.Modal.getOrCreateInstance(modal).hide();
                renderizarHistorial(json.interacciones || []);
                mostrarToast(
                    json.mensaje || 'Correo enviado y registrado en el historial.',
                    false
                );

                try {
                    window.IMPE_SEGUIMIENTO_PANEL_CACHE?.invalidar?.(
                        seguimientoId
                    );
                } catch (error) {
                    // La actualización visual principal ya se realizó.
                }

                document.dispatchEvent(
                    new CustomEvent('impe:interaction-informative-saved', {
                        detail: {
                            seguimientoId: seguimientoId,
                            canal: 'CORREO'
                        }
                    })
                );
            } catch (error) {
                console.error(error);
                mostrarError('No fue posible comunicarse con el sistema.');
            } finally {
                enviando = false;
                boton.disabled = false;
                boton.innerHTML = htmlOriginal;
            }
        }

        document.addEventListener('click', function (event) {
            const boton = event.target.closest('[data-work-email-button]');

            if (!boton || boton.disabled) {
                return;
            }

            event.preventDefault();
            event.stopPropagation();
            abrirCorreo();
        }, true);
    });
})();
