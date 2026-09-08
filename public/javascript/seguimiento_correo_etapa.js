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
                        '<form data-followup-mail-form>' +
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
                                '<div class="alert alert-info d-none mb-3" data-followup-mail-info></div>' +
                                '<div class="mb-3">' +
                                    '<label class="form-label" for="followup_mail_to">Para</label>' +
                                    '<input class="form-control" id="followup_mail_to" type="email" readonly data-followup-mail-to>' +
                                '</div>' +
                                '<div class="mb-3">' +
                                    '<label class="form-label" for="followup_mail_subject">Asunto</label>' +
                                    '<input class="form-control" id="followup_mail_subject" name="asunto" maxlength="255" required data-followup-mail-subject>' +
                                '</div>' +
                                '<div class="mb-0">' +
                                    '<label class="form-label" for="followup_mail_body">Mensaje</label>' +
                                    '<textarea class="form-control" id="followup_mail_body" name="cuerpo" rows="9" maxlength="20000" required data-followup-mail-body></textarea>' +
                                '</div>' +
                            '</div>' +
                            '<div class="modal-footer system-form-modal-footer">' +
                                '<span class="me-auto text-muted small">' +
                                    'Cada envío quedará registrado por separado en el historial.' +
                                '</span>' +
                                '<button type="button" class="btn btn-system-cancel" data-bs-dismiss="modal">Cancelar</button>' +
                                '<button type="submit" class="btn btn-system-save" data-followup-mail-send>' +
                                    '<i class="bi bi-send me-2"></i>Enviar correo' +
                                '</button>' +
                            '</div>' +
                        '</form>' +
                    '</div>' +
                '</div>';

            document.body.appendChild(modal);
            modal.querySelector('[data-followup-mail-form]').addEventListener('submit', enviarCorreo);
            return modal;
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
            info.textContent = '';
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
            para.value = 'Consultando...';
            asunto.value = '';
            cuerpo.value = '';
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

                const total = Number(json.correo.total_enviados || 0);
                if (total > 0) {
                    info.textContent = total === 1
                        ? 'Ya se envió 1 correo de seguimiento. Puedes enviar otro si todavía necesitan coordinar detalles.'
                        : 'Ya se enviaron ' + total + ' correos de seguimiento. Puedes continuar la conversación mientras sea necesario.';
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
                    headers: { 'X-Requested-With': 'fetch' }
                });
                const json = await respuesta.json();

                if (!respuesta.ok || !json.ok) {
                    mostrarError(json.mensaje || 'No fue posible enviar el correo de seguimiento.');
                    return;
                }

                bootstrap.Modal.getOrCreateInstance(modal).hide();
                mostrarToast(json.mensaje || 'Correo de seguimiento enviado correctamente.');

                const proxima = offcanvas.querySelector('[data-work-next-action]');
                if (proxima) {
                    proxima.textContent = 'Actualizando ruta...';
                }
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
