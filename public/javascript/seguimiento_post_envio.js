(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        const offcanvas = document.getElementById('offcanvasSeguimientoTrabajo');

        if (!offcanvas || !window.bootstrap) {
            return;
        }

        const urlGuardar = 'index.php?controller=seguimientoFlujo&action=registrarPostEnvio';
        const urlBorradorConvenio = 'index.php?controller=seguimientoFlujo&action=borradorConvenio';
        const accionesPostEnvio = new Set([
            'REGISTRAR_RESPUESTA',
            'REGISTRAR_SEGUIMIENTO_CORREO',
            'AGENDAR_REUNION',
            'REGISTRAR_REUNION_REALIZADA',
            'ENVIAR_DOCUMENTACION_CONVENIO',
            'REGISTRAR_CONVENIO_RECIBIDO',
            'REGISTRAR_CONVENIO_CORREGIDO',
            'APROBAR_CONVENIO_RECIBIDO',
            'SOLICITAR_CORRECCIONES_CONVENIO',
            'FORMALIZAR_CONVENIO'
        ]);
        let seguimientoActualId = 0;
        let accionActual = '';

        const escapar = function (valor) {
            const div = document.createElement('div');
            div.textContent = String(valor || '');
            return div.innerHTML;
        };

        const escaparAtributo = function (valor) {
            return String(valor || '')
                .replace(/&/g, '&amp;')
                .replace(/"/g, '&quot;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;');
        };

        const fechaHoraLocal = function (fecha) {
            const valor = fecha instanceof Date ? fecha : new Date();
            const pad = function (numero) {
                return String(numero).padStart(2, '0');
            };

            return valor.getFullYear() + '-' +
                pad(valor.getMonth() + 1) + '-' +
                pad(valor.getDate()) + 'T' +
                pad(valor.getHours()) + ':' +
                pad(valor.getMinutes());
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
                '<div class="modal-dialog modal-dialog-centered modal-lg system-form-dialog">' +
                    '<div class="modal-content system-form-modal">' +
                        '<form data-post-envio-form>' +
                            '<div class="modal-header system-form-modal-header">' +
                                '<div>' +
                                    '<h5 class="system-form-modal-title" data-post-envio-title>Registrar avance</h5>' +
                                    '<p class="system-form-modal-subtitle" data-post-envio-subtitle></p>' +
                                '</div>' +
                                '<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>' +
                            '</div>' +
                            '<div class="modal-body">' +
                                '<div class="alert alert-danger d-none" data-post-envio-error></div>' +
                                '<div data-post-envio-fields></div>' +
                            '</div>' +
                            '<div class="modal-footer system-form-modal-footer">' +
                                '<button type="button" class="btn btn-system-cancel" data-bs-dismiss="modal">Cancelar</button>' +
                                '<button type="submit" class="btn btn-system-save" data-post-envio-save>' +
                                    '<i class="bi bi-check2-circle me-2"></i>Guardar avance' +
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
                    const campo = modal.querySelector('[name="contactar_despues_at"]');
                    const requiereFecha =
                        event.target.value === 'CONTACTAR_DESPUES';

                    if (bloque) {
                        bloque.classList.toggle('d-none', !requiereFecha);
                    }

                    if (campo) {
                        campo.required = requiereFecha;

                        if (requiereFecha) {
                            const minimo = new Date(Date.now() + (5 * 60 * 1000));
                            campo.min = fechaHoraLocal(minimo);
                        } else {
                            campo.required = false;
                            campo.removeAttribute('min');
                            campo.value = '';
                        }
                    }
                }

                if (event.target.matches('[name="reunion_resultado"]')) {
                    const resultado = String(event.target.value || '');
                    const bloqueFecha = modal.querySelector(
                        '[data-reunion-seguimiento-fecha]'
                    );
                    const campoFecha = modal.querySelector(
                        '[name="reunion_seguimiento_fecha"]'
                    );
                    const nota = modal.querySelector(
                        '[data-reunion-resultado-ayuda]'
                    );
                    const bloqueContexto = modal.querySelector(
                        '[data-reunion-seguimiento-contexto]'
                    );
                    const camposContexto = bloqueContexto
                        ? bloqueContexto.querySelectorAll(
                            'input, textarea, select'
                        )
                        : [];
                    const requiereSeguimiento =
                        resultado === 'REQUIERE_SEGUIMIENTO';

                    if (bloqueFecha) {
                        bloqueFecha.classList.toggle(
                            'd-none',
                            !requiereSeguimiento
                        );
                    }

                    if (bloqueContexto) {
                        bloqueContexto.classList.toggle(
                            'd-none',
                            !requiereSeguimiento
                        );
                    }

                    camposContexto.forEach(function (campo) {
                        campo.required = requiereSeguimiento;
                        if (!requiereSeguimiento) {
                            campo.value = '';
                        }
                    });

                    if (campoFecha) {
                        campoFecha.required = requiereSeguimiento;

                        if (requiereSeguimiento) {
                            const minimo = new Date(
                                Date.now() + (5 * 60 * 1000)
                            );
                            campoFecha.min = fechaHoraLocal(minimo);
                        } else {
                            campoFecha.required = false;
                            campoFecha.removeAttribute('min');
                            campoFecha.value = '';
                        }
                    }

                    if (nota) {
                        if (resultado === 'AVANZAR_CONVENIO') {
                            nota.textContent =
                                'Al guardar, la ruta avanzará al Paso 13 para iniciar el proceso de convenio.';
                        } else if (resultado === 'REQUIERE_SEGUIMIENTO') {
                            nota.textContent =
                                'La reunión quedará registrada y el seguimiento permanecerá en el Paso 12 hasta la fecha que indiques.';
                        } else if (resultado === 'NO_INTERESADO') {
                            nota.textContent =
                                'Al guardar, el seguimiento se cerrará como no interesado. Los acuerdos quedarán en el expediente.';
                        } else {
                            nota.textContent =
                                'Selecciona el resultado que corresponda a lo acordado durante la reunión.';
                        }
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
                    '<div class="col-md-6 d-none" data-reunion-seguimiento-fecha>' +
                        '<label class="form-label">Dar seguimiento el</label>' +
                        '<input class="form-control" type="datetime-local" name="reunion_seguimiento_fecha">' +
                    '</div>' +
                    '<div class="col-12">' +
                        '<div class="reunion-resultado-ayuda" data-reunion-resultado-ayuda>' +
                            'Selecciona el resultado que corresponda a lo acordado durante la reunión.' +
                        '</div>' +
                    '</div>' +
                    '<div class="col-12 d-none" data-reunion-seguimiento-contexto>' +
                        '<div class="reunion-followup-plan">' +
                            '<div class="reunion-followup-plan-heading">' +
                                '<strong>Definir el seguimiento</strong>' +
                                '<span>Deja claro qué debe ocurrir antes de la próxima revisión.</span>' +
                            '</div>' +
                            '<div class="row g-3">' +
                                '<div class="col-12">' +
                                    '<label class="form-label">Pendiente acordado</label>' +
                                    '<textarea class="form-control" name="reunion_seguimiento_objetivo" rows="2" maxlength="1200" placeholder="Ej. La institución revisará la propuesta con Dirección y confirmará si desea avanzar."></textarea>' +
                                '</div>' +
                                '<div class="col-md-6">' +
                                    '<label class="form-label">Pendiente de</label>' +
                                    '<select class="form-select" name="reunion_seguimiento_pendiente_de">' +
                                        '<option value="">Selecciona una opción</option>' +
                                        '<option value="INSTITUCION">Institución</option>' +
                                        '<option value="FUNDACION">Fundación Red</option>' +
                                        '<option value="AMBOS">Ambos</option>' +
                                    '</select>' +
                                '</div>' +
                                '<div class="col-md-6">' +
                                    '<label class="form-label">Acción prevista</label>' +
                                    '<select class="form-select" name="reunion_seguimiento_accion">' +
                                        '<option value="">Selecciona una opción</option>' +
                                        '<option value="LLAMAR">Llamar</option>' +
                                        '<option value="ENVIAR_CORREO">Enviar correo</option>' +
                                        '<option value="ESPERAR_RESPUESTA">Esperar respuesta</option>' +
                                        '<option value="REVISAR_DOCUMENTACION">Revisar documentación</option>' +
                                        '<option value="CONFIRMAR_AUTORIZACION">Confirmar autorización</option>' +
                                        '<option value="OTRO">Otra acción</option>' +
                                    '</select>' +
                                '</div>' +
                            '</div>' +
                        '</div>' +
                    '</div>' +
                    '<div class="col-12">' +
                        '<label class="form-label">Acuerdos y resultado</label>' +
                        '<textarea class="form-control" name="reunion_resultado_notas" rows="5" maxlength="5000" placeholder="Registra los acuerdos, responsables y próximos pasos..." required></textarea>' +
                    '</div>' +
                '</div>';
        };

        const camposDocumentacionConvenio = function (correo) {
            const datos = correo || {};
            const documentos = Array.isArray(datos.documentos)
                ? datos.documentos
                : [];

            const tarjetas = documentos.map(function (documento) {
                const tipo = escapar(documento.tipo || 'Archivo');
                const nombre = escapar(documento.nombre || 'Documento');
                const detalle = escapar(documento.detalle || '');
                const icono = String(documento.tipo || '').toUpperCase() === 'PDF'
                    ? 'bi-file-earmark-pdf'
                    : 'bi-file-earmark-word';

                return '<div class="convenio-document-card">' +
                    '<span class="convenio-document-icon"><i class="bi ' + icono + '"></i></span>' +
                    '<div>' +
                        '<strong>' + nombre + '</strong>' +
                        '<span>' + tipo + '</span>' +
                        '<p>' + detalle + '</p>' +
                    '</div>' +
                '</div>';
            }).join('');

            return '' +
                '<div class="convenio-mail-summary">' +
                    '<div>' +
                        '<span class="convenio-mail-summary-label">Destinatario</span>' +
                        '<strong>' + escapar(datos.para || '') + '</strong>' +
                    '</div>' +
                    '<span class="convenio-mail-summary-badge"><i class="bi bi-paperclip"></i> 2 adjuntos</span>' +
                '</div>' +
                '<div class="row g-3">' +
                    '<div class="col-md-5">' +
                        '<label class="form-label">Fecha de la carta propuesta</label>' +
                        '<input class="form-control" type="date" name="carta_fecha" value="' +
                            escaparAtributo(datos.carta_fecha || '') + '" required>' +
                        '<div class="form-text convenio-field-help">Es el único dato que se modifica dentro de la carta antes de convertirla a PDF.</div>' +
                    '</div>' +
                    '<div class="col-md-7">' +
                        '<label class="form-label">Para</label>' +
                        '<input class="form-control" type="email" value="' +
                            escaparAtributo(datos.para || '') + '" readonly>' +
                    '</div>' +
                    '<div class="col-12">' +
                        '<label class="form-label">Asunto</label>' +
                        '<input class="form-control" type="text" name="asunto" maxlength="255" value="' +
                            escaparAtributo(datos.asunto || '') + '" required>' +
                    '</div>' +
                    '<div class="col-12">' +
                        '<label class="form-label">Mensaje</label>' +
                        '<textarea class="form-control" name="cuerpo" rows="8" maxlength="20000" required>' +
                            escapar(datos.cuerpo || '') +
                        '</textarea>' +
                    '</div>' +
                '</div>' +
                '<div class="convenio-documents-heading">' +
                    '<span>Documentos que se enviarán</span>' +
                    '<small>La carta se genera en PDF y el convenio permanece editable.</small>' +
                '</div>' +
                '<div class="convenio-document-grid">' + tarjetas + '</div>' +
                '<div class="convenio-mail-note">' +
                    '<i class="bi bi-info-circle"></i>' +
                    '<span>El convenio se adjunta sin rellenar ni modificar su contenido para que el aliado capture sus datos.</span>' +
                '</div>';
        };

        const fechaLocalHoy = function () {
            const ahora = new Date();
            const local = new Date(
                ahora.getTime() - (ahora.getTimezoneOffset() * 60000)
            );
            return local.toISOString().slice(0, 10);
        };

        const camposConvenioRecibido = function (corregido) {
            const esCorreccion = Boolean(corregido);
            const titulo = esCorreccion
                ? 'Nueva versión corregida del convenio'
                : 'Convenio requisitado por la institución';
            const detalle = esCorreccion
                ? 'Adjunta el documento actualizado que devolvió la institución. La versión anterior permanecerá en el expediente.'
                : 'Adjunta el archivo que devolvió el aliado. Se conservará separado del convenio editable que se envió originalmente.';
            const placeholder = esCorreccion
                ? 'Ej. La institución devolvió la versión con las correcciones solicitadas.'
                : 'Ej. El aliado devolvió el convenio requisitado para revisión.';

            return '' +
                '<div class="convenio-recepcion-intro">' +
                    '<span class="convenio-recepcion-icon">' +
                        '<i class="bi bi-file-earmark-arrow-up"></i>' +
                    '</span>' +
                    '<div>' +
                        '<strong>' + escapar(titulo) + '</strong>' +
                        '<p>' + escapar(detalle) + '</p>' +
                    '</div>' +
                '</div>' +
                '<div class="row g-3">' +
                    '<div class="col-12 convenio-recepcion-upload">' +
                        '<label class="form-label">Archivo recibido</label>' +
                        '<input class="form-control" type="file" name="convenio_archivo" accept=".docx,.pdf,application/pdf,application/vnd.openxmlformats-officedocument.wordprocessingml.document" required>' +
                        '<div class="form-text">Formatos permitidos: DOCX o PDF. Tamaño máximo: 15 MB.</div>' +
                    '</div>' +
                    '<div class="col-md-5">' +
                        '<label class="form-label">Fecha de recepción</label>' +
                        '<input class="form-control" type="date" name="convenio_recibido_fecha" value="' +
                            escaparAtributo(fechaLocalHoy()) + '" max="' +
                            escaparAtributo(fechaLocalHoy()) + '" required>' +
                    '</div>' +
                    '<div class="col-12">' +
                        '<label class="form-label">Observaciones</label>' +
                        '<textarea class="form-control" name="convenio_recibido_notas" rows="4" maxlength="5000" placeholder="' +
                            escaparAtributo(placeholder) + '"></textarea>' +
                    '</div>' +
                '</div>' +
                '<div class="convenio-mail-note convenio-recepcion-note">' +
                    '<i class="bi bi-shield-check"></i>' +
                    '<span>El archivo queda resguardado como una nueva versión y no reemplaza los documentos anteriores.</span>' +
                '</div>';
        };

        const camposAprobacionConvenio = function () {
            return '' +
                '<div class="convenio-review-confirm">' +
                    '<span class="convenio-review-confirm-icon">' +
                        '<i class="bi bi-check2-circle"></i>' +
                    '</span>' +
                    '<div>' +
                        '<strong>Confirmar que el convenio está correcto</strong>' +
                        '<p>Esta decisión habilitará la formalización de la versión vigente. Si detectaste algún ajuste pendiente, cancela y usa “Solicitar correcciones”.</p>' +
                    '</div>' +
                '</div>' +
                '<div class="mt-3">' +
                    '<label class="form-label">Observaciones de revisión <span class="text-muted">(opcional)</span></label>' +
                    '<textarea class="form-control" name="convenio_revision_notas" rows="4" maxlength="5000" placeholder="Ej. Datos institucionales revisados y correctos."></textarea>' +
                '</div>';
        };

        const camposCorreccionesConvenio = function () {
            return '' +
                '<div class="convenio-review-warning">' +
                    '<i class="bi bi-pencil-square"></i>' +
                    '<div>' +
                        '<strong>Solicitar una nueva versión</strong>' +
                        '<p>Describe con claridad qué debe corregirse. La versión actual se conservará en el expediente y el seguimiento quedará esperando el documento corregido.</p>' +
                    '</div>' +
                '</div>' +
                '<div class="mt-3">' +
                    '<label class="form-label">Correcciones requeridas</label>' +
                    '<textarea class="form-control" name="convenio_revision_notas" rows="5" maxlength="5000" placeholder="Ej. Corregir razón social, representante legal y domicilio..." required></textarea>' +
                '</div>';
        };

        const camposConvenio = function () {
            return '' +
                '<div class="row g-3">' +
                    '<div class="col-md-5">' +
                        '<label class="form-label">Fecha de formalización</label>' +
                        '<input class="form-control" type="date" name="convenio_fecha" value="' +
                            escaparAtributo(fechaLocalHoy()) + '" max="' +
                            escaparAtributo(fechaLocalHoy()) + '" required>' +
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
                    campos: camposReunionRealizada(),
                    boton: '<i class="bi bi-check2-circle"></i>Finalizar reunión'
                },
                REGISTRAR_CONVENIO_RECIBIDO: {
                    titulo: 'Registrar convenio recibido',
                    subtitulo: 'Adjunta el convenio requisitado que devolvió la institución para incorporarlo al expediente.',
                    campos: camposConvenioRecibido(false),
                    boton: '<i class="bi bi-cloud-arrow-up me-2"></i>Registrar convenio recibido'
                },
                REGISTRAR_CONVENIO_CORREGIDO: {
                    titulo: 'Registrar convenio corregido',
                    subtitulo: 'Adjunta la nueva versión que devolvió la institución después de las correcciones.',
                    campos: camposConvenioRecibido(true),
                    boton: '<i class="bi bi-cloud-arrow-up me-2"></i>Registrar nueva versión'
                },
                APROBAR_CONVENIO_RECIBIDO: {
                    titulo: 'Aprobar convenio recibido',
                    subtitulo: 'Confirma que la versión vigente está correcta antes de formalizarla.',
                    campos: camposAprobacionConvenio(),
                    boton: '<i class="bi bi-check2-circle me-2"></i>Aprobar convenio'
                },
                SOLICITAR_CORRECCIONES_CONVENIO: {
                    titulo: 'Solicitar correcciones',
                    subtitulo: 'Registra los ajustes que debe realizar la institución en el convenio.',
                    campos: camposCorreccionesConvenio(),
                    boton: '<i class="bi bi-pencil-square me-2"></i>Registrar correcciones'
                },
                FORMALIZAR_CONVENIO: {
                    titulo: 'Formalizar convenio',
                    subtitulo: 'Registra la fecha de formalización y, si aplica, las observaciones finales para concluir la ruta del Analista.',
                    campos: camposConvenio(),
                    boton: '<i class="bi bi-file-earmark-check me-2"></i>Formalizar convenio'
                }
            };
            return mapa[codigo] || null;
        };

        const abrir = async function (codigo) {
            if (seguimientoActualId <= 0) {
                return;
            }

            accionActual = codigo;
            const modal = asegurarModal();
            const form = modal.querySelector('[data-post-envio-form]');
            const titulo = modal.querySelector('[data-post-envio-title]');
            const subtitulo = modal.querySelector('[data-post-envio-subtitle]');
            const campos = modal.querySelector('[data-post-envio-fields]');
            const error = modal.querySelector('[data-post-envio-error]');
            const boton = modal.querySelector('[data-post-envio-save]');

            form.reset();
            error.classList.add('d-none');
            error.textContent = '';

            if (codigo === 'ENVIAR_DOCUMENTACION_CONVENIO') {
                titulo.textContent = 'Enviar documentación de convenio';
                subtitulo.textContent = 'Revisa la fecha, el mensaje y los dos archivos antes de enviarlos a la institución.';
                campos.innerHTML =
                    '<div class="convenio-loading">' +
                        '<span class="spinner-border spinner-border-sm" aria-hidden="true"></span>' +
                        '<span>Preparando correo y documentos...</span>' +
                    '</div>';
                boton.innerHTML = '<i class="bi bi-send me-2"></i>Enviar documentación';
                boton.disabled = true;
                bootstrap.Modal.getOrCreateInstance(modal).show();

                try {
                    const respuesta = await fetch(
                        urlBorradorConvenio + '&seguimiento_id=' +
                            encodeURIComponent(seguimientoActualId),
                        {
                            headers: { 'X-Requested-With': 'fetch' },
                            cache: 'no-store'
                        }
                    );
                    const json = await respuesta.json();

                    if (!respuesta.ok || !json.ok || !json.correo) {
                        mostrarError(
                            json.mensaje ||
                            'No fue posible preparar la documentación del convenio.'
                        );
                        campos.innerHTML = '';
                        return;
                    }

                    campos.innerHTML = camposDocumentacionConvenio(json.correo);
                    boton.disabled = false;
                } catch (errorPeticion) {
                    console.error(errorPeticion);
                    campos.innerHTML = '';
                    mostrarError(
                        'No fue posible comunicarse con el sistema para preparar los documentos.'
                    );
                }

                return;
            }

            const config = configuracion(codigo);
            if (!config) {
                return;
            }

            titulo.textContent = config.titulo;
            subtitulo.textContent = config.subtitulo;
            campos.innerHTML = config.campos;
            boton.innerHTML = config.boton ||
                '<i class="bi bi-check2-circle me-2"></i>Guardar avance';
            boton.disabled = false;
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
            const htmlOriginalBoton = boton.innerHTML;
            const esEnvioDocumentacionConvenio =
                accionActual === 'ENVIAR_DOCUMENTACION_CONVENIO';
            const esRegistroConvenioRecibido =
                accionActual === 'REGISTRAR_CONVENIO_RECIBIDO' ||
                accionActual === 'REGISTRAR_CONVENIO_CORREGIDO';
            const esAprobacionConvenio =
                accionActual === 'APROBAR_CONVENIO_RECIBIDO';
            const esCorreccionConvenio =
                accionActual === 'SOLICITAR_CORRECCIONES_CONVENIO';

            datos.set('seguimiento_id', String(seguimientoActualId));
            datos.set('accion', accionActual);

            boton.disabled = true;
            if (esEnvioDocumentacionConvenio) {
                boton.innerHTML =
                    '<span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>' +
                    'Enviando...';
            } else if (esRegistroConvenioRecibido) {
                boton.innerHTML =
                    '<span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>' +
                    'Registrando...';
            } else if (esAprobacionConvenio) {
                boton.innerHTML =
                    '<span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>' +
                    'Aprobando...';
            } else if (esCorreccionConvenio) {
                boton.innerHTML =
                    '<span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>' +
                    'Guardando...';
            }
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

                document.dispatchEvent(new CustomEvent('impe:post-envio-updated', {
                    detail: {
                        seguimientoId: seguimientoActualId,
                        accion: accionActual
                    }
                }));
            } catch (error) {
                console.error(error);
                mostrarError('No fue posible comunicarse con el sistema.');
            } finally {
                boton.disabled = false;
                if (
                    esEnvioDocumentacionConvenio ||
                    esRegistroConvenioRecibido ||
                    esAprobacionConvenio ||
                    esCorreccionConvenio
                ) {
                    boton.innerHTML = htmlOriginalBoton;
                }
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
            void abrir(codigo);
        }, true);

        offcanvas.addEventListener('hidden.bs.offcanvas', function () {
            seguimientoActualId = 0;
            accionActual = '';
        });
    });
})();
