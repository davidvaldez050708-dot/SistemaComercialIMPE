(function () {
    'use strict';

    let seguimientoTrabajoActual = 0;

    document.addEventListener('click', function (event) {
        const botonTrabajo = event.target.closest('[data-work-follow]');

        if (botonTrabajo) {
            seguimientoTrabajoActual = Number(
                botonTrabajo.getAttribute('data-work-follow-id') || 0
            );
        }

        const botonAgendaFlujo = event.target.closest(
            '[data-flow-action="AGENDAR_REUNION"]'
        );

        if (!botonAgendaFlujo || !botonAgendaFlujo.closest('[data-work-flow-section]')) {
            return;
        }

        event.preventDefault();
        event.stopImmediatePropagation();

        const seguimientoId = seguimientoTrabajoActual > 0
            ? seguimientoTrabajoActual
            : Number(
                botonAgendaFlujo
                    .closest('[data-work-flow-section]')
                    ?.getAttribute('data-seguimiento-id') || 0
            );

        const destino = 'index.php?controller=agendaReunion&action=index' +
            (seguimientoId > 0
                ? '&seguimiento_id=' + encodeURIComponent(seguimientoId)
                : '');

        window.location.href = destino;
    }, true);

    document.addEventListener('DOMContentLoaded', function () {
        const root = document.querySelector('[data-agenda-root]');

        if (!root || !window.bootstrap) {
            return;
        }

        const rolId = Number(root.getAttribute('data-agenda-role') || 0);
        const mesActual = root.getAttribute('data-agenda-month') || '';
        const seguimientoInicial = Number(
            root.getAttribute('data-agenda-initial-follow') || 0
        );
        const reunionInicial = Number(
            root.getAttribute('data-agenda-initial-meeting') || 0
        );
        const reuniones = leerJson('agendaReunionesData');
        const seguimientos = leerJson('agendaSeguimientosData');
        const reunionesPorId = new Map(
            reuniones.map(function (item) {
                return [Number(item.id || 0), item];
            })
        );
        const seguimientosPorId = new Map(
            seguimientos.map(function (item) {
                return [Number(item.id || 0), item];
            })
        );

        const modalSolicitudEl = document.getElementById('modalAgendaSolicitud');
        const modalDetalleEl = document.getElementById('modalAgendaDetalle');
        const modalSolicitud = modalSolicitudEl
            ? bootstrap.Modal.getOrCreateInstance(modalSolicitudEl)
            : null;
        const modalDetalle = modalDetalleEl
            ? bootstrap.Modal.getOrCreateInstance(modalDetalleEl)
            : null;

        const escapar = function (valor) {
            const div = document.createElement('div');
            div.textContent = String(valor ?? '');
            return div.innerHTML;
        };

        const valorSeguro = function (valor, fallback) {
            const texto = String(valor ?? '').trim();
            return texto !== '' ? texto : (fallback || '—');
        };

        const estadoClase = function (estado) {
            return String(estado || 'SOLICITADA')
                .toLowerCase()
                .replaceAll('_', '-');
        };

        const fechaInput = function (fecha) {
            const texto = String(fecha || '').trim();
            if (texto.length < 16) {
                return '';
            }
            return texto.slice(0, 16).replace(' ', 'T');
        };

        const etiquetaModalidad = function (modalidad) {
            const mapa = {
                VIRTUAL: 'Virtual',
                PRESENCIAL: 'Presencial',
                HIBRIDA: 'Híbrida'
            };
            return mapa[String(modalidad || '').toUpperCase()] || 'Por definir';
        };

        const mostrarToast = function (mensaje) {
            let contenedor = document.querySelector('.toast-container');

            if (!contenedor) {
                contenedor = document.createElement('div');
                contenedor.className =
                    'toast-container position-fixed top-0 end-0 p-3';
                document.body.appendChild(contenedor);
            }

            const toast = document.createElement('div');
            toast.className = 'toast system-toast';
            toast.setAttribute('role', 'status');
            toast.innerHTML =
                '<div class="toast-body">' +
                    '<i class="bi bi-check-circle"></i>' +
                    '<span>' + escapar(mensaje || 'Cambios guardados.') + '</span>' +
                '</div>';
            contenedor.appendChild(toast);
            toast.addEventListener('hidden.bs.toast', function () {
                toast.remove();
            });
            bootstrap.Toast.getOrCreateInstance(toast, {
                autohide: true,
                delay: 2600
            }).show();
        };

        const mostrarErrorSolicitud = function (mensaje) {
            const error = modalSolicitudEl?.querySelector('[data-agenda-form-error]');
            if (!error) {
                return;
            }
            error.textContent = String(mensaje || 'No fue posible guardar la solicitud.');
            error.classList.remove('d-none');
        };

        const itemContexto = function (etiqueta, valor) {
            return '<div class="agenda-context-item">' +
                '<span>' + escapar(etiqueta) + '</span>' +
                '<b>' + escapar(valorSeguro(valor, '—')) + '</b>' +
            '</div>';
        };

        const itemDetalle = function (etiqueta, valor) {
            return '<div class="agenda-detail-item">' +
                '<span>' + escapar(etiqueta) + '</span>' +
                '<b>' + escapar(valorSeguro(valor, '—')) + '</b>' +
            '</div>';
        };

        const contextoSeguimiento = function (seguimiento) {
            if (!seguimiento) {
                return '';
            }

            return '' +
                '<strong>' + escapar(valorSeguro(seguimiento.nombre_entidad, 'Institución')) + '</strong>' +
                '<div class="agenda-context-grid">' +
                    itemContexto('Municipio', seguimiento.municipio_nombre) +
                    itemContexto('Contacto', seguimiento.contacto_nombre) +
                    itemContexto('Cargo / área', seguimiento.contacto_cargo) +
                    itemContexto('Correo', seguimiento.contacto_correo) +
                '</div>';
        };

        const actualizarContextoSolicitud = function () {
            const select = modalSolicitudEl?.querySelector('[data-agenda-follow-select]');
            const bloque = modalSolicitudEl?.querySelector('[data-agenda-follow-context]');

            if (!select || !bloque) {
                return;
            }

            const seguimiento = seguimientosPorId.get(Number(select.value || 0));
            bloque.classList.toggle('d-none', !seguimiento);
            bloque.innerHTML = seguimiento ? contextoSeguimiento(seguimiento) : '';
        };

        const abrirSolicitud = function (seguimientoId) {
            if (!modalSolicitudEl || !modalSolicitud) {
                return;
            }

            const form = modalSolicitudEl.querySelector('[data-agenda-request-form]');
            const error = modalSolicitudEl.querySelector('[data-agenda-form-error]');
            const select = modalSolicitudEl.querySelector('[data-agenda-follow-select]');

            form?.reset();
            error?.classList.add('d-none');

            if (select && seguimientoId > 0 && seguimientosPorId.has(seguimientoId)) {
                select.value = String(seguimientoId);
            }

            actualizarContextoSolicitud();
            modalSolicitud.show();
        };

        const cabeceraDetalle = function (reunion) {
            const estado = escapar(valorSeguro(reunion.estado_etiqueta, 'Pendiente'));
            return '' +
                '<div class="agenda-detail-card">' +
                    '<span class="agenda-status-pill is-' + escapar(estadoClase(reunion.estado)) + '">' + estado + '</span>' +
                    '<strong>' + escapar(valorSeguro(reunion.nombre_entidad, 'Reunión')) + '</strong>' +
                    '<div class="agenda-detail-grid">' +
                        itemDetalle('Fecha y hora', reunion.fecha_legible) +
                        itemDetalle('Modalidad', etiquetaModalidad(reunion.modalidad)) +
                        itemDetalle('Duración', Number(reunion.duracion_minutos || 60) + ' min') +
                        itemDetalle('Municipio', reunion.municipio_nombre) +
                        itemDetalle('Contacto', reunion.contacto_nombre) +
                        itemDetalle('Correo', reunion.contacto_correo) +
                        itemDetalle('Analista', reunion.analista_nombre) +
                        itemDetalle('Cuenta Clave', reunion.cuenta_clave_nombre) +
                    '</div>' +
                '</div>';
        };

        const datosConexion = function (reunion) {
            const zoom = String(reunion.zoom_url || '').trim();
            const ubicacion = String(reunion.ubicacion || '').trim();
            let html = '<div class="agenda-detail-grid mt-3">';

            if (zoom !== '') {
                html += '<div class="agenda-detail-item"><span>Zoom</span><b><a href="' + escapar(zoom) + '" target="_blank" rel="noopener noreferrer">Abrir enlace</a></b></div>';
            }

            if (ubicacion !== '') {
                html += itemDetalle('Lugar', ubicacion);
            }

            html += '</div>';
            return html;
        };

        const selectDuracion = function (valorActual) {
            const actual = Number(valorActual || 60);
            const opciones = [30, 45, 60, 90, 120];
            return '<select class="form-select system-form-control" name="duracion_minutos" required>' +
                opciones.map(function (valor) {
                    return '<option value="' + valor + '"' +
                        (valor === actual ? ' selected' : '') +
                        '>' + valor + ' min</option>';
                }).join('') +
            '</select>';
        };

        const selectModalidad = function (valorActual) {
            const actual = String(valorActual || 'VIRTUAL').toUpperCase();
            const opciones = [
                ['VIRTUAL', 'Virtual'],
                ['PRESENCIAL', 'Presencial'],
                ['HIBRIDA', 'Híbrida']
            ];
            return '<select class="form-select system-form-control" name="modalidad" required>' +
                opciones.map(function (opcion) {
                    return '<option value="' + opcion[0] + '"' +
                        (opcion[0] === actual ? ' selected' : '') +
                        '>' + opcion[1] + '</option>';
                }).join('') +
            '</select>';
        };

        const contenidoAnalista = function (reunion) {
            const estado = String(reunion.estado || '');
            let html = cabeceraDetalle(reunion);

            html += '<div class="agenda-inline-note">' +
                '<strong>Objetivo:</strong> ' + escapar(valorSeguro(reunion.objetivo, 'Sin objetivo registrado.')) +
            '</div>';

            if (estado === 'SOLICITADA') {
                html += '<div class="agenda-action-box">' +
                    '<h6>Esperando a Cuenta Clave</h6>' +
                    '<p>La propuesta ya fue enviada. Cuenta Clave debe confirmar la fecha y agregar los datos de Zoom.</p>' +
                    '<div class="agenda-inline-note is-warning">' +
                        '<i class="bi bi-hourglass-split"></i> Pendiente de confirmación KAM.' +
                    '</div>' +
                '</div>';
                return html;
            }

            if (estado === 'CAMBIO_SOLICITADO') {
                html += '<div class="agenda-action-box">' +
                    '<h6>Cuenta Clave solicita un cambio</h6>' +
                    '<p>' + escapar(valorSeguro(reunion.cambio_motivo, 'Revisa la solicitud y propón otra fecha.')) + '</p>' +
                    '<form data-agenda-action-form data-agenda-action="reprogramar">' +
                        '<input type="hidden" name="reunion_id" value="' + Number(reunion.id || 0) + '">' +
                        '<div class="row g-3">' +
                            '<div class="col-md-6">' +
                                '<label class="form-label">Nueva fecha y hora</label>' +
                                '<input class="form-control system-form-control" type="datetime-local" name="fecha_propuesta" value="' + escapar(fechaInput(reunion.fecha_propuesta)) + '" required>' +
                            '</div>' +
                            '<div class="col-md-3">' +
                                '<label class="form-label">Duración</label>' +
                                selectDuracion(reunion.duracion_minutos) +
                            '</div>' +
                            '<div class="col-md-3">' +
                                '<label class="form-label">Modalidad</label>' +
                                selectModalidad(reunion.modalidad) +
                            '</div>' +
                            '<div class="col-12">' +
                                '<label class="form-label">Objetivo</label>' +
                                '<input class="form-control system-form-control" type="text" name="objetivo" maxlength="500" value="' + escapar(reunion.objetivo || '') + '" required>' +
                            '</div>' +
                            '<div class="col-12">' +
                                '<label class="form-label">Notas para Cuenta Clave</label>' +
                                '<textarea class="form-control system-form-control" name="notas_analista" rows="3" maxlength="4000">' + escapar(reunion.notas_analista || '') + '</textarea>' +
                            '</div>' +
                        '</div>' +
                        '<div class="agenda-action-row">' +
                            '<button class="btn btn-system-save" type="submit"><i class="bi bi-send"></i> Enviar nueva propuesta</button>' +
                        '</div>' +
                    '</form>' +
                '</div>';
                return html;
            }

            if (estado === 'CONFIRMADA') {
                html += '<div class="agenda-action-box">' +
                    '<h6>Reunión confirmada</h6>' +
                    '<p>Cuenta Clave ya confirmó la fecha. Envía estos datos a la institución y registra el envío para completar el paso 11.</p>' +
                    datosConexion(reunion) +
                    '<form data-agenda-action-form data-agenda-action="marcarCorreoEnviado" class="agenda-email-preview">' +
                        '<input type="hidden" name="reunion_id" value="' + Number(reunion.id || 0) + '">' +
                        '<div class="agenda-email-recipient"><strong>Para:</strong> ' + escapar(valorSeguro(reunion.contacto_correo, 'Sin correo válido')) + '</div>' +
                        '<div>' +
                            '<label class="form-label">Asunto</label>' +
                            '<input class="form-control system-form-control" type="text" name="asunto" maxlength="255" value="' + escapar(reunion.correo_sugerido_asunto || '') + '" required>' +
                        '</div>' +
                        '<div>' +
                            '<label class="form-label">Mensaje</label>' +
                            '<textarea class="form-control system-form-control" name="cuerpo" rows="9" required>' + escapar(reunion.correo_sugerido_cuerpo || '') + '</textarea>' +
                        '</div>' +
                        '<div class="agenda-inline-note">' +
                            '<i class="bi bi-info-circle"></i> Mientras activamos Hostinger Mail API, envía este mensaje desde el correo corporativo y después registra aquí que fue enviado.' +
                        '</div>' +
                        '<div class="agenda-action-row">' +
                            '<button class="btn btn-system-light" type="button" data-copy-email><i class="bi bi-copy"></i> Copiar mensaje</button>' +
                            '<button class="btn btn-system-save" type="submit"><i class="bi bi-envelope-check"></i> Registrar correo enviado</button>' +
                        '</div>' +
                    '</form>' +
                '</div>';
                return html;
            }

            if (estado === 'CORREO_ENVIADO') {
                html += '<div class="agenda-action-box">' +
                    '<h6>Reunión formalmente agendada</h6>' +
                    '<p>La confirmación ya fue registrada y el seguimiento puede continuar al paso 12 cuando se realice la reunión.</p>' +
                    datosConexion(reunion) +
                    '<div class="agenda-inline-note is-success"><i class="bi bi-check2-circle"></i> Confirmación enviada a la institución.</div>' +
                '</div>';
            }

            return html;
        };

        const contenidoCuentaClave = function (reunion) {
            const estado = String(reunion.estado || '');
            let html = cabeceraDetalle(reunion);

            html += '<div class="agenda-inline-note">' +
                '<strong>Objetivo:</strong> ' + escapar(valorSeguro(reunion.objetivo, 'Sin objetivo registrado.')) +
                (String(reunion.notas_analista || '').trim() !== ''
                    ? '<br><strong>Notas del Analista:</strong> ' + escapar(reunion.notas_analista)
                    : '') +
            '</div>';

            if (estado !== 'SOLICITADA') {
                html += '<div class="agenda-action-box">' +
                    '<h6>' + escapar(valorSeguro(reunion.estado_etiqueta, 'Solicitud atendida')) + '</h6>' +
                    '<p>Esta solicitud ya fue procesada.</p>' +
                    datosConexion(reunion) +
                '</div>';
                return html;
            }

            const modalidad = String(reunion.modalidad || '').toUpperCase();
            const requiereZoom = modalidad === 'VIRTUAL' || modalidad === 'HIBRIDA';
            const requiereLugar = modalidad === 'PRESENCIAL' || modalidad === 'HIBRIDA';

            html += '<div class="agenda-action-box">' +
                '<h6>Confirmar propuesta</h6>' +
                '<p>Si la fecha funciona, genera la reunión en Zoom y adjunta el enlace antes de confirmar.</p>' +
                '<form data-agenda-action-form data-agenda-action="confirmar">' +
                    '<input type="hidden" name="reunion_id" value="' + Number(reunion.id || 0) + '">' +
                    '<div class="row g-3">' +
                        (requiereZoom
                            ? '<div class="col-12"><label class="form-label">Enlace de Zoom</label><input class="form-control system-form-control" type="url" name="zoom_url" maxlength="600" placeholder="https://zoom.us/j/..." required></div>'
                            : '') +
                        (requiereLugar
                            ? '<div class="col-12"><label class="form-label">Lugar</label><input class="form-control system-form-control" type="text" name="ubicacion" maxlength="500" placeholder="Dirección, sala o punto de reunión" required></div>'
                            : '') +
                        '<div class="col-12"><label class="form-label">Nota para el Analista</label><textarea class="form-control system-form-control" name="notas_kam" rows="3" maxlength="4000" placeholder="Indicaciones, participantes u observaciones..."></textarea></div>' +
                    '</div>' +
                    '<div class="agenda-action-row">' +
                        '<button class="btn btn-system-save" type="submit"><i class="bi bi-calendar-check"></i> Confirmar reunión</button>' +
                    '</div>' +
                '</form>' +
            '</div>' +
            '<div class="agenda-action-box">' +
                '<h6>¿La fecha no funciona?</h6>' +
                '<p>Solicita al Analista que proponga otra fecha y explica el motivo.</p>' +
                '<form data-agenda-action-form data-agenda-action="solicitarCambio">' +
                    '<input type="hidden" name="reunion_id" value="' + Number(reunion.id || 0) + '">' +
                    '<textarea class="form-control system-form-control" name="motivo" rows="3" maxlength="4000" placeholder="Ej. Ese horario ya está ocupado; propone una fecha por la tarde..." required></textarea>' +
                    '<div class="agenda-action-row">' +
                        '<button class="btn btn-system-light" type="submit"><i class="bi bi-arrow-repeat"></i> Solicitar cambio</button>' +
                    '</div>' +
                '</form>' +
            '</div>';

            return html;
        };

        const abrirDetalle = function (reunionId) {
            const reunion = reunionesPorId.get(Number(reunionId || 0));

            if (!reunion || !modalDetalleEl || !modalDetalle) {
                return;
            }

            const titulo = modalDetalleEl.querySelector('[data-agenda-detail-title]');
            const subtitulo = modalDetalleEl.querySelector('[data-agenda-detail-subtitle]');
            const cuerpo = modalDetalleEl.querySelector('[data-agenda-detail-body]');

            if (titulo) {
                titulo.textContent = valorSeguro(reunion.nombre_entidad, 'Detalle de reunión');
            }
            if (subtitulo) {
                subtitulo.textContent = reunion.fecha_legible || '';
            }
            if (cuerpo) {
                cuerpo.innerHTML = rolId === 6
                    ? contenidoCuentaClave(reunion)
                    : contenidoAnalista(reunion);
            }

            modalDetalle.show();
        };

        const enviarFormulario = async function (form, accion) {
            const boton = form.querySelector('[type="submit"]');
            const datos = new FormData(form);
            boton && (boton.disabled = true);

            try {
                const respuesta = await fetch(
                    'index.php?controller=agendaReunion&action=' + encodeURIComponent(accion),
                    {
                        method: 'POST',
                        body: datos,
                        headers: { 'X-Requested-With': 'fetch' }
                    }
                );
                const json = await respuesta.json();

                if (!respuesta.ok || !json.ok) {
                    throw new Error(json.mensaje || 'No fue posible guardar los cambios.');
                }

                mostrarToast(json.mensaje || 'Cambios guardados.');
                const reunionId = Number(json.reunion_id || datos.get('reunion_id') || 0);

                window.setTimeout(function () {
                    const params = new URLSearchParams();
                    if (mesActual) {
                        params.set('mes', mesActual);
                    }
                    if (reunionId > 0) {
                        params.set('reunion_id', String(reunionId));
                    }
                    window.location.href =
                        'index.php?controller=agendaReunion&action=index&' + params.toString();
                }, 350);
            } catch (error) {
                const caja = document.createElement('div');
                caja.className = 'alert alert-danger agenda-form-error mt-3';
                caja.textContent = error.message || 'No fue posible guardar los cambios.';
                form.querySelector('.agenda-form-error')?.remove();
                form.prepend(caja);
            } finally {
                boton && (boton.disabled = false);
            }
        };

        document.addEventListener('click', function (event) {
            const nuevo = event.target.closest('[data-agenda-new-request]');
            if (nuevo) {
                abrirSolicitud(0);
                return;
            }

            const reunion = event.target.closest('[data-agenda-meeting]');
            if (reunion) {
                abrirDetalle(Number(reunion.getAttribute('data-agenda-meeting') || 0));
                return;
            }

            const copiar = event.target.closest('[data-copy-email]');
            if (copiar) {
                const form = copiar.closest('form');
                const asunto = form?.querySelector('[name="asunto"]')?.value || '';
                const cuerpo = form?.querySelector('[name="cuerpo"]')?.value || '';
                const texto = 'Asunto: ' + asunto + '\n\n' + cuerpo;

                if (navigator.clipboard && texto.trim() !== '') {
                    navigator.clipboard.writeText(texto).then(function () {
                        copiar.innerHTML = '<i class="bi bi-check2"></i> Copiado';
                    });
                }
            }
        });

        modalSolicitudEl?.querySelector('[data-agenda-follow-select]')
            ?.addEventListener('change', actualizarContextoSolicitud);

        modalSolicitudEl?.querySelector('[data-agenda-request-form]')
            ?.addEventListener('submit', async function (event) {
                event.preventDefault();
                const form = event.currentTarget;
                const boton = form.querySelector('[data-agenda-request-save]');
                const datos = new FormData(form);
                boton && (boton.disabled = true);

                try {
                    const respuesta = await fetch(
                        'index.php?controller=agendaReunion&action=solicitar',
                        {
                            method: 'POST',
                            body: datos,
                            headers: { 'X-Requested-With': 'fetch' }
                        }
                    );
                    const json = await respuesta.json();

                    if (!respuesta.ok || !json.ok) {
                        mostrarErrorSolicitud(json.mensaje || 'No fue posible guardar la solicitud.');
                        return;
                    }

                    modalSolicitud.hide();
                    mostrarToast(json.mensaje || 'Solicitud enviada.');
                    window.setTimeout(function () {
                        window.location.href =
                            'index.php?controller=agendaReunion&action=index&reunion_id=' +
                            encodeURIComponent(Number(json.reunion_id || 0));
                    }, 350);
                } catch (error) {
                    mostrarErrorSolicitud('No fue posible comunicarse con el sistema.');
                } finally {
                    boton && (boton.disabled = false);
                }
            });

        modalDetalleEl?.addEventListener('submit', function (event) {
            const form = event.target.closest('[data-agenda-action-form]');
            if (!form) {
                return;
            }

            event.preventDefault();
            enviarFormulario(
                form,
                String(form.getAttribute('data-agenda-action') || '')
            );
        });

        if (reunionInicial > 0 && reunionesPorId.has(reunionInicial)) {
            window.setTimeout(function () {
                abrirDetalle(reunionInicial);
            }, 120);
            return;
        }

        if (seguimientoInicial > 0) {
            const reunionExistente = reuniones.find(function (item) {
                return Number(item.seguimiento_id || 0) === seguimientoInicial &&
                    String(item.estado || '') !== 'CANCELADA';
            });

            if (reunionExistente) {
                window.setTimeout(function () {
                    abrirDetalle(Number(reunionExistente.id || 0));
                }, 120);
            } else if (seguimientosPorId.has(seguimientoInicial)) {
                window.setTimeout(function () {
                    abrirSolicitud(seguimientoInicial);
                }, 120);
            }
        }
    });

    function leerJson(id) {
        const elemento = document.getElementById(id);
        if (!elemento) {
            return [];
        }

        try {
            const datos = JSON.parse(elemento.textContent || '[]');
            return Array.isArray(datos) ? datos : [];
        } catch (error) {
            console.error('Agenda: no fue posible leer los datos.', error);
            return [];
        }
    }
})();
