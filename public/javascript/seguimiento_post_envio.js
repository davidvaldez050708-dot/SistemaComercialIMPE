(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        const offcanvas = document.getElementById('offcanvasSeguimientoTrabajo');

        if (!offcanvas || !window.bootstrap) {
            return;
        }

        const urlGuardar = 'index.php?controller=seguimientoFlujo&action=registrarPostEnvio';
        const accionesPostEnvio = new Set([
            'REGISTRAR_RESPUESTA',
            'REGISTRAR_SEGUIMIENTO_CORREO',
            'AGENDAR_REUNION',
            'REGISTRAR_REUNION_REALIZADA',
            'FORMALIZAR_CONVENIO'
        ]);
        let seguimientoActualId = 0;
        let accionActual = '';

        const escapar = function (valor) {
            const div = document.createElement('div');
            div.textContent = String(valor || '');
            return div.innerHTML;
        };

        const asegurarModal = function () {
            let modal = document.getElementById('modalSeguimientoPostEnvio');

            if (modal) {
                return modal;
            }

            modal = document.createElement('div');
            modal.className = 'modal fade';
            modal.id = 'modalSeguimientoPostEnvio';
            modal.tabIndex = -1;
            modal.setAttribute('aria-hidden', 'true');
            modal.innerHTML =
                '<div class="modal-dialog modal-dialog-centered modal-lg">' +
                    '<div class="modal-content">' +
                        '<form data-post-envio-form>' +
                            '<div class="modal-header">' +
                                '<div>' +
                                    '<h5 class="modal-title" data-post-envio-title>Registrar avance</h5>' +
                                    '<p class="mb-0 text-muted small" data-post-envio-subtitle></p>' +
                                '</div>' +
                                '<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>' +
                            '</div>' +
                            '<div class="modal-body">' +
                                '<div class="alert alert-danger d-none" data-post-envio-error></div>' +
                                '<div data-post-envio-fields></div>' +
                            '</div>' +
                            '<div class="modal-footer">' +
                                '<button type="button" class="btn btn-system-light" data-bs-dismiss="modal">Cancelar</button>' +
                                '<button type="submit" class="btn btn-system-save" data-post-envio-save>' +
                                    '<i class="bi bi-check2-circle"></i> Guardar avance' +
                                '</button>' +
                            '</div>' +
                        '</form>' +
                    '</div>' +
                '</div>';
            document.body.appendChild(modal);

            modal.querySelector('[data-post-envio-form]').addEventListener('submit', guardar);
            modal.addEventListener('change', function (event) {
                if (event.target.matches('[name="respuesta_tipo"]')) {
                    const bloque = modal.querySelector('[data-contactar-despues]');
                    if (bloque) {
                        bloque.classList.toggle(
                            'd-none',
                            event.target.value !== 'CONTACTAR_DESPUES'
                        );
                    }
                }
            });

            return modal;
        };

        const camposRespuesta = function () {
            return '' +
                '<div class="row g-3">' +
                    '<div class="col-md-6">' +
                        '<label class="form-label">¿Qué respondió la institución?</label>' +
                        '<select class="form-select" name="respuesta_tipo" required>' +
                            '<option value="">Selecciona una opción</option>' +
                            '<option value="INTERESADO">Interesado / respuesta positiva</option>' +
                            '<option value="MAS_INFORMACION">Solicita más información</option>' +
                            '<option value="QUIERE_REUNION">Quiere agendar una reunión</option>' +
                            '<option value="CONTACTAR_DESPUES">Contactar más adelante</option>' +
                            '<option value="NO_INTERESADO">No interesado</option>' +
                        '</select>' +
                    '</div>' +
                    '<div class="col-md-6">' +
                        '<label class="form-label">Canal de respuesta</label>' +
                        '<select class="form-select" name="respuesta_canal" required>' +
                            '<option value="CORREO">Correo</option>' +
                            '<option value="LLAMADA">Llamada</option>' +
                            '<option value="WHATSAPP">WhatsApp</option>' +
                        '</select>' +
                    '</div>' +
                    '<div class="col-12">' +
                        '<label class="form-label">Respuesta recibida</label>' +
                        '<textarea class="form-control" name="respuesta_texto" rows="4" maxlength="4000" placeholder="Resume qué respondió la institución..." required></textarea>' +
                    '</div>' +
                    '<div class="col-md-6 d-none" data-contactar-despues>' +
                        '<label class="form-label">Retomar contacto el</label>' +
                        '<input class="form-control" type="datetime-local" name="contactar_despues_at">' +
                    '</div>' +
                '</div>';
        };

        const camposSeguimientoCorreo = function () {
            return '' +
                '<div class="mb-3">' +
                    '<label class="form-label">Seguimiento por correo</label>' +
                    '<textarea class="form-control" name="seguimiento_correo_notas" rows="5" maxlength="4000" placeholder="Ej. Se respondió agradeciendo el interés y se propusieron horarios para reunión..." required></textarea>' +
                    '<div class="form-text">Por ahora este paso registra el seguimiento realizado. Cuando Hostinger Mail API esté activa podremos conectar también el envío desde aquí.</div>' +
                '</div>';
        };

        const camposReunion = function () {
            return '' +
                '<div class="row g-3">' +
                    '<div class="col-md-6">' +
                        '<label class="form-label">Fecha y hora</label>' +
                        '<input class="form-control" type="datetime-local" name="reunion_fecha" required>' +
                    '</div>' +
                    '<div class="col-md-6">' +
                        '<label class="form-label">Modalidad</label>' +
                        '<select class="form-select" name="reunion_modalidad" required>' +
                            '<option value="">Selecciona una opción</option>' +
                            '<option value="VIRTUAL">Virtual</option>' +
                            '<option value="PRESENCIAL">Presencial</option>' +
                            '<option value="HIBRIDA">Híbrida</option>' +
                        '</select>' +
                    '</div>' +
                    '<div class="col-12">' +
                        '<label class="form-label">Enlace o lugar</label>' +
                        '<input class="form-control" type="text" name="reunion_lugar_enlace" maxlength="500" placeholder="Meet, Zoom, dirección, sala..." required>' +
                    '</div>' +
                    '<div class="col-12">' +
                        '<label class="form-label">Notas</label>' +
                        '<textarea class="form-control" name="reunion_notas" rows="3" maxlength="4000" placeholder="Objetivo, asistentes o información importante..."></textarea>' +
                    '</div>' +
                '</div>';
        };

        const camposReunionRealizada = function () {
            return '' +
                '<div class="row g-3">' +
                    '<div class="col-md-6">' +
                        '<label class="form-label">Resultado de la reunión</label>' +
                        '<select class="form-select" name="reunion_resultado" required>' +
                            '<option value="">Selecciona una opción</option>' +
                            '<option value="AVANZAR_CONVENIO">Avanzar hacia convenio</option>' +
                            '<option value="REQUIERE_SEGUIMIENTO">Requiere seguimiento adicional</option>' +
                            '<option value="NO_INTERESADO">No interesado</option>' +
                        '</select>' +
                    '</div>' +
                    '<div class="col-12">' +
                        '<label class="form-label">Acuerdos y resultado</label>' +
                        '<textarea class="form-control" name="reunion_resultado_notas" rows="5" maxlength="5000" placeholder="Registra los acuerdos, responsables y próximos pasos..." required></textarea>' +
                    '</div>' +
                '</div>';
        };

        const camposConvenio = function () {
            return '' +
                '<div class="row g-3">' +
                    '<div class="col-md-5">' +
                        '<label class="form-label">Fecha del convenio</label>' +
                        '<input class="form-control" type="date" name="convenio_fecha" required>' +
                    '</div>' +
                    '<div class="col-md-7">' +
                        '<label class="form-label">Folio / referencia</label>' +
                        '<input class="form-control" type="text" name="convenio_referencia" maxlength="180" placeholder="Ej. CONV-2026-015" required>' +
                    '</div>' +
                    '<div class="col-12">' +
                        '<label class="form-label">Observaciones</label>' +
                        '<textarea class="form-control" name="convenio_notas" rows="4" maxlength="5000" placeholder="Alcance, vigencia, acuerdos o notas importantes..."></textarea>' +
                    '</div>' +
                '</div>';
        };

        const configuracion = function (codigo) {
            const mapa = {
                REGISTRAR_RESPUESTA: {
                    titulo: 'Registrar respuesta',
                    subtitulo: 'Captura qué respondió la institución para continuar la ruta.',
                    campos: camposRespuesta()
                },
                REGISTRAR_SEGUIMIENTO_CORREO: {
                    titulo: 'Seguimiento por correo',
                    subtitulo: 'Registra el mensaje de seguimiento enviado después de recibir respuesta.',
                    campos: camposSeguimientoCorreo()
                },
                AGENDAR_REUNION: {
                    titulo: 'Agendar reunión',
                    subtitulo: 'Define cuándo y cómo se realizará el acercamiento.',
                    campos: camposReunion()
                },
                REGISTRAR_REUNION_REALIZADA: {
                    titulo: 'Registrar reunión realizada',
                    subtitulo: 'Documenta el resultado y los acuerdos alcanzados.',
                    campos: camposReunionRealizada()
                },
                FORMALIZAR_CONVENIO: {
                    titulo: 'Formalizar convenio',
                    subtitulo: 'Captura la referencia final para concluir la ruta del Analista.',
                    campos: camposConvenio()
                }
            };
            return mapa[codigo] || null;
        };

        const abrir = function (codigo) {
            const config = configuracion(codigo);

            if (!config || seguimientoActualId <= 0) {
                return;
            }

            accionActual = codigo;
            const modal = asegurarModal();
            const form = modal.querySelector('[data-post-envio-form]');
            form.reset();
            modal.querySelector('[data-post-envio-title]').textContent = config.titulo;
            modal.querySelector('[data-post-envio-subtitle]').textContent = config.subtitulo;
            modal.querySelector('[data-post-envio-fields]').innerHTML = config.campos;
            modal.querySelector('[data-post-envio-error]').classList.add('d-none');
            modal.querySelector('[data-post-envio-error]').textContent = '';
            bootstrap.Modal.getOrCreateInstance(modal).show();
        };

        const mostrarError = function (mensaje) {
            const modal = asegurarModal();
            const error = modal.querySelector('[data-post-envio-error]');
            error.textContent = String(mensaje || 'No fue posible guardar el avance.');
            error.classList.remove('d-none');
        };

        const mostrarExito = function (mensaje) {
            const contenedor = document.querySelector('.toast-container');
            if (!contenedor) {
                return;
            }

            const toast = document.createElement('div');
            toast.className = 'toast system-toast';
            toast.setAttribute('role', 'status');
            toast.innerHTML =
                '<div class="toast-body">' +
                    '<i class="bi bi-check-circle"></i>' +
                    '<span>' + escapar(mensaje || 'Avance guardado correctamente.') + '</span>' +
                '</div>';
            contenedor.appendChild(toast);
            toast.addEventListener('hidden.bs.toast', function () { toast.remove(); });
            bootstrap.Toast.getOrCreateInstance(toast, { autohide: true, delay: 3200 }).show();
        };

        async function guardar(event) {
            event.preventDefault();

            if (!accionActual || seguimientoActualId <= 0) {
                return;
            }

            const modal = asegurarModal();
            const form = event.currentTarget;
            const boton = modal.querySelector('[data-post-envio-save]');
            const datos = new FormData(form);
            datos.set('seguimiento_id', String(seguimientoActualId));
            datos.set('accion', accionActual);

            boton.disabled = true;
            modal.querySelector('[data-post-envio-error]').classList.add('d-none');

            try {
                const respuesta = await fetch(urlGuardar, {
                    method: 'POST',
                    body: datos,
                    headers: { 'X-Requested-With': 'fetch' }
                });
                const json = await respuesta.json();

                if (!respuesta.ok || !json.ok) {
                    mostrarError(json.mensaje || 'No fue posible guardar el avance.');
                    return;
                }

                bootstrap.Modal.getOrCreateInstance(modal).hide();
                mostrarExito(json.mensaje || 'Avance guardado correctamente.');

                const proxima = offcanvas.querySelector('[data-work-next-action]');
                if (proxima) {
                    proxima.textContent = 'Actualizando ruta...';
                }
            } catch (error) {
                console.error(error);
                mostrarError('No fue posible comunicarse con el sistema.');
            } finally {
                boton.disabled = false;
            }
        }

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
            if (!accionesPostEnvio.has(codigo)) {
                return;
            }

            event.preventDefault();
            event.stopImmediatePropagation();
            abrir(codigo);
        }, true);

        offcanvas.addEventListener('hidden.bs.offcanvas', function () {
            seguimientoActualId = 0;
            accionActual = '';
        });
    });
})();
