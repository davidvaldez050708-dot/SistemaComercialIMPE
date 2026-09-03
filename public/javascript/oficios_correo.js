(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        const parametros = new URLSearchParams(window.location.search);
        const esDetalle =
            parametros.get('controller') === 'seguimientoVinculacion' &&
            parametros.get('action') === 'detalle';
        const seguimientoDetalleId = Number(parametros.get('id') || 0);
        const urlBorrador = 'index.php?controller=oficioCorreo&action=borrador';
        const urlGuardar = 'index.php?controller=oficioCorreo&action=guardarBorrador';
        let seguimientoTrabajoId = 0;
        let seguimientoModalId = 0;
        let temporizador = null;

        const mostrarToast = function (mensaje, esError) {
            const contenedor = document.querySelector('.toast-container');

            if (!contenedor || !window.bootstrap) {
                return;
            }

            const toast = document.createElement('div');
            toast.className = 'toast system-toast' +
                (esError ? ' system-toast-error' : '');
            toast.setAttribute('role', esError ? 'alert' : 'status');
            toast.setAttribute('aria-live', esError ? 'assertive' : 'polite');
            toast.setAttribute('aria-atomic', 'true');
            toast.innerHTML =
                '<div class="toast-body">' +
                    '<i class="bi ' +
                        (esError ? 'bi-exclamation-circle' : 'bi-check2-circle') +
                    '"></i>' +
                    '<span></span>' +
                '</div>';
            toast.querySelector('span').textContent = mensaje;
            contenedor.appendChild(toast);

            const instancia = new bootstrap.Toast(toast, {
                autohide: true,
                delay: esError ? 5000 : 3600
            });
            toast.addEventListener('hidden.bs.toast', function () {
                toast.remove();
            });
            instancia.show();
        };

        const crearModal = function () {
            let modal = document.getElementById('modalBorradorCorreoOficio');

            if (modal) {
                return modal;
            }

            modal = document.createElement('div');
            modal.className = 'modal fade';
            modal.id = 'modalBorradorCorreoOficio';
            modal.tabIndex = -1;
            modal.setAttribute('aria-labelledby', 'modalBorradorCorreoOficioTitulo');
            modal.setAttribute('aria-hidden', 'true');
            modal.innerHTML =
                '<div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">' +
                    '<div class="modal-content system-form-modal">' +
                        '<div class="modal-header system-form-modal-header">' +
                            '<div>' +
                                '<h5 class="system-form-modal-title" id="modalBorradorCorreoOficioTitulo">' +
                                    'Preparar correo institucional' +
                                '</h5>' +
                                '<p class="system-form-modal-subtitle" data-mail-subtitle>' +
                                    'Borrador de prueba. No se enviará ningún correo.' +
                                '</p>' +
                            '</div>' +
                            '<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>' +
                        '</div>' +
                        '<div class="modal-body">' +
                            '<div class="alert alert-info mb-3" data-mail-test-note>' +
                                '<i class="bi bi-info-circle me-2"></i>' +
                                '<strong>Modo de prueba:</strong> guardar este borrador no envía mensajes ni utiliza Hostinger.' +
                            '</div>' +
                            '<div class="mb-3">' +
                                '<label class="form-label" for="oficio_mail_to">Para</label>' +
                                '<input class="form-control" id="oficio_mail_to" data-mail-to readonly>' +
                            '</div>' +
                            '<div class="mb-3">' +
                                '<label class="form-label" for="oficio_mail_subject">Asunto</label>' +
                                '<input class="form-control" id="oficio_mail_subject" maxlength="255" data-mail-subject>' +
                            '</div>' +
                            '<div class="mb-3">' +
                                '<label class="form-label" for="oficio_mail_body">Mensaje</label>' +
                                '<textarea class="form-control" id="oficio_mail_body" rows="12" data-mail-body></textarea>' +
                            '</div>' +
                            '<div class="border rounded-3 p-3 bg-light">' +
                                '<span class="d-block text-muted small mb-1">Adjunto</span>' +
                                '<strong data-mail-attachment>—</strong>' +
                                '<span class="d-block text-muted small mt-1">PDF del oficio generado previamente.</span>' +
                            '</div>' +
                        '</div>' +
                        '<div class="modal-footer system-form-modal-footer">' +
                            '<span class="me-auto text-muted small" data-mail-status></span>' +
                            '<button type="button" class="btn btn-system-cancel" data-bs-dismiss="modal">Cerrar</button>' +
                            '<button type="button" class="btn btn-system-save" data-mail-save>' +
                                '<i class="bi bi-floppy me-2"></i>Guardar borrador' +
                            '</button>' +
                        '</div>' +
                    '</div>' +
                '</div>';
            document.body.appendChild(modal);

            return modal;
        };

        const textoPdfGenerado = function (contenedor, selectorStatus, selectorPdf) {
            if (!contenedor) {
                return false;
            }

            const status = String(
                contenedor.querySelector(selectorStatus)?.textContent || ''
            ).toLowerCase();

            return status.includes('pdf generado') ||
                Boolean(contenedor.querySelector(selectorPdf));
        };

        const crearBoton = function (atributo, clase, texto) {
            const boton = document.createElement('button');
            boton.type = 'button';
            boton.className = clase;
            boton.setAttribute(atributo, '');
            boton.innerHTML = '<i class="bi bi-envelope-paper me-1"></i>' + texto;
            return boton;
        };

        const asegurarBotonTrabajo = function () {
            const seccion = document.querySelector('[data-work-oficio-section]');

            if (!seccion) {
                return;
            }

            const pdfGenerado = textoPdfGenerado(
                seccion,
                '[data-work-oficio-status]',
                '[data-work-view-pdf]'
            );
            const acciones = seccion.querySelector('.linkage-work-verify-row');
            let boton = seccion.querySelector('[data-work-mail-oficio]');

            if (!pdfGenerado || !acciones) {
                boton?.classList.add('d-none');
                return;
            }

            if (!boton) {
                boton = crearBoton(
                    'data-work-mail-oficio',
                    'btn btn-system-light linkage-work-small-button',
                    'Preparar correo'
                );
                acciones.appendChild(boton);
            }

            boton.classList.remove('d-none');
        };

        const asegurarBotonDetalle = function () {
            if (!esDetalle || seguimientoDetalleId <= 0) {
                return;
            }

            const bloque = document.querySelector('[data-detail-oficio-action]');

            if (!bloque) {
                return;
            }

            const pdfGenerado = textoPdfGenerado(
                bloque,
                '[data-detail-oficio-status]',
                '[data-detail-view-pdf]'
            );
            let boton = bloque.querySelector('[data-detail-mail-oficio]');

            if (!pdfGenerado) {
                boton?.classList.add('d-none');
                return;
            }

            if (!boton) {
                boton = crearBoton(
                    'data-detail-mail-oficio',
                    'btn btn-system-light',
                    'Preparar correo'
                );
                bloque.appendChild(boton);
            }

            boton.classList.remove('d-none');
        };

        const actualizarBotones = function () {
            asegurarBotonTrabajo();
            asegurarBotonDetalle();
        };

        const programarActualizacion = function () {
            window.clearTimeout(temporizador);
            temporizador = window.setTimeout(actualizarBotones, 70);
        };

        const actualizarEtiquetaBotones = function (guardado, soloConsulta) {
            const texto = soloConsulta
                ? 'Ver correo'
                : (guardado ? 'Editar correo' : 'Preparar correo');

            document.querySelectorAll(
                '[data-work-mail-oficio], [data-detail-mail-oficio]'
            ).forEach(function (boton) {
                boton.innerHTML =
                    '<i class="bi bi-envelope-paper me-1"></i>' + texto;
            });
        };

        const configurarModal = function (correo) {
            const modal = crearModal();
            const campoPara = modal.querySelector('[data-mail-to]');
            const campoAsunto = modal.querySelector('[data-mail-subject]');
            const campoCuerpo = modal.querySelector('[data-mail-body]');
            const adjunto = modal.querySelector('[data-mail-attachment]');
            const estado = modal.querySelector('[data-mail-status]');
            const subtitulo = modal.querySelector('[data-mail-subtitle]');
            const botonGuardar = modal.querySelector('[data-mail-save]');
            const soloConsulta = Boolean(correo?.solo_consulta);
            const puedeEditar = Boolean(correo?.puede_editar);
            const guardado = Boolean(correo?.guardado);

            campoPara.value = String(correo?.para || '');
            campoAsunto.value = String(correo?.asunto || '');
            campoCuerpo.value = String(correo?.cuerpo || '');
            adjunto.textContent = String(correo?.adjunto_nombre || '—');
            campoAsunto.readOnly = !puedeEditar;
            campoCuerpo.readOnly = !puedeEditar;
            botonGuardar.classList.toggle('d-none', !puedeEditar);
            botonGuardar.disabled = false;
            botonGuardar.innerHTML = '<i class="bi bi-floppy me-2"></i>Guardar borrador';

            if (subtitulo) {
                subtitulo.textContent = soloConsulta
                    ? 'Consulta del borrador. Solo el Analista responsable puede editarlo.'
                    : 'Borrador de prueba. No se enviará ningún correo.';
            }

            if (estado) {
                if (guardado) {
                    estado.textContent = 'Borrador guardado';
                } else if (soloConsulta) {
                    estado.textContent = 'El Analista aún no ha guardado este borrador.';
                } else {
                    estado.textContent = 'Plantilla provisional sin guardar';
                }
            }

            modal.dataset.puedeEditar = puedeEditar ? '1' : '0';
            modal.dataset.guardado = guardado ? '1' : '0';
            actualizarEtiquetaBotones(guardado, soloConsulta);

            return modal;
        };

        const abrirCorreo = async function (seguimientoId, boton) {
            if (!seguimientoId || !boton) {
                return;
            }

            const htmlOriginal = boton.innerHTML;
            boton.disabled = true;
            boton.innerHTML =
                '<span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>' +
                'Cargando...';

            try {
                const respuesta = await fetch(
                    urlBorrador + '&seguimiento_id=' + encodeURIComponent(seguimientoId),
                    {
                        headers: {
                            'X-Requested-With': 'fetch'
                        },
                        cache: 'no-store'
                    }
                );
                const datos = await respuesta.json();

                if (!datos.ok || !datos.correo) {
                    throw new Error(
                        datos.mensaje || 'No fue posible preparar el borrador del correo.'
                    );
                }

                seguimientoModalId = seguimientoId;
                const modal = configurarModal(datos.correo);
                bootstrap.Modal.getOrCreateInstance(modal).show();
            } catch (error) {
                console.error(error);
                mostrarToast(
                    error.message || 'No fue posible preparar el borrador del correo.',
                    true
                );
            } finally {
                boton.disabled = false;
                if (boton.innerHTML.includes('Cargando')) {
                    boton.innerHTML = htmlOriginal;
                }
            }
        };

        const guardarCorreo = async function (boton) {
            const modal = crearModal();

            if (!seguimientoModalId || modal.dataset.puedeEditar !== '1') {
                return;
            }

            const asunto = String(
                modal.querySelector('[data-mail-subject]')?.value || ''
            ).trim();
            const cuerpo = String(
                modal.querySelector('[data-mail-body]')?.value || ''
            ).trim();

            if (asunto === '' || cuerpo === '') {
                mostrarToast('El asunto y el mensaje son obligatorios.', true);
                return;
            }

            const htmlOriginal = boton.innerHTML;
            boton.disabled = true;
            boton.innerHTML =
                '<span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>' +
                'Guardando...';

            const formulario = new FormData();
            formulario.append('seguimiento_id', String(seguimientoModalId));
            formulario.append('asunto', asunto);
            formulario.append('cuerpo', cuerpo);

            try {
                const respuesta = await fetch(urlGuardar, {
                    method: 'POST',
                    headers: {
                        'X-Requested-With': 'fetch'
                    },
                    body: formulario
                });
                const datos = await respuesta.json();

                if (!datos.ok || !datos.correo) {
                    throw new Error(
                        datos.mensaje || 'No fue posible guardar el borrador del correo.'
                    );
                }

                configurarModal(datos.correo);
                mostrarToast('Borrador de correo guardado correctamente.', false);
            } catch (error) {
                console.error(error);
                mostrarToast(
                    error.message || 'No fue posible guardar el borrador del correo.',
                    true
                );
            } finally {
                boton.disabled = false;
                boton.innerHTML = htmlOriginal;
            }
        };

        document.addEventListener('click', function (event) {
            const botonTrabajo = event.target.closest('[data-work-follow]');

            if (botonTrabajo) {
                seguimientoTrabajoId = Number(
                    botonTrabajo.getAttribute('data-work-follow-id') || 0
                );
                programarActualizacion();
                return;
            }

            const botonCorreoTrabajo = event.target.closest('[data-work-mail-oficio]');

            if (botonCorreoTrabajo) {
                event.preventDefault();
                abrirCorreo(seguimientoTrabajoId, botonCorreoTrabajo);
                return;
            }

            const botonCorreoDetalle = event.target.closest('[data-detail-mail-oficio]');

            if (botonCorreoDetalle) {
                event.preventDefault();
                abrirCorreo(seguimientoDetalleId, botonCorreoDetalle);
                return;
            }

            const botonGuardar = event.target.closest('[data-mail-save]');

            if (botonGuardar) {
                event.preventDefault();
                guardarCorreo(botonGuardar);
            }
        });

        document
            .getElementById('offcanvasSeguimientoTrabajo')
            ?.addEventListener('hidden.bs.offcanvas', function () {
                seguimientoTrabajoId = 0;
            });

        crearModal().addEventListener('hidden.bs.modal', function () {
            seguimientoModalId = 0;
        });

        if (window.MutationObserver) {
            const observador = new MutationObserver(programarActualizacion);
            observador.observe(document.body, {
                childList: true,
                subtree: true,
                characterData: true
            });
        }

        actualizarBotones();
    });
})();
