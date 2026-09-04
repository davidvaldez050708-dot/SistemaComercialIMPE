(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        const root = document.querySelector('[data-agenda-root]');
        const modal = document.getElementById('modalAgendaDetalle');
        const body = modal?.querySelector('[data-agenda-detail-body]');

        if (!root || !modal || !body) {
            return;
        }

        const rolId = Number(root.getAttribute('data-agenda-role') || 0);
        let reunionActualId = Number(root.getAttribute('data-agenda-initial-meeting') || 0);
        const reuniones = leerJson('agendaReunionesData');
        const porId = new Map(
            reuniones.map(function (item) {
                return [Number(item.id || 0), item];
            })
        );

        document.addEventListener('click', function (event) {
            const boton = event.target.closest('[data-agenda-meeting]');
            if (!boton) {
                return;
            }
            reunionActualId = Number(boton.getAttribute('data-agenda-meeting') || 0);
        }, true);

        document.addEventListener('submit', function (event) {
            const form = event.target.closest('#modalAgendaDetalle [data-agenda-action-form]');
            if (!form) {
                return;
            }

            const reunion = porId.get(reunionActualId);
            if (!reunion || Number(reunion.es_reprogramacion || 0) !== 1) {
                return;
            }

            const accion = String(form.getAttribute('data-agenda-action') || '');
            if (accion === 'reprogramar') {
                form.setAttribute('data-agenda-action', 'completarReprogramacion');
            } else if (accion === 'confirmar') {
                form.setAttribute('data-agenda-action', 'confirmarReprogramacion');
            } else if (accion === 'marcarCorreoEnviado') {
                form.setAttribute('data-agenda-action', 'marcarCorreoReprogramacionEnviado');
            }
        }, true);

        const observer = new MutationObserver(function () {
            prepararDetalle();
        });
        observer.observe(body, { childList: true, subtree: true });

        function prepararDetalle() {
            const reunion = porId.get(reunionActualId);
            if (!reunion) {
                return;
            }

            if (Number(reunion.es_reprogramacion || 0) === 1) {
                adaptarReprogramacionActiva(reunion);
            }

            if (body.querySelector('[data-reprogramacion-box]')) {
                return;
            }

            const estado = String(reunion.estado || '');
            if (!['CONFIRMADA', 'CORREO_ENVIADO'].includes(estado)) {
                return;
            }

            const box = document.createElement('div');
            box.className = 'agenda-action-box';
            box.setAttribute('data-reprogramacion-box', '');

            if (rolId === 4) {
                box.innerHTML = formularioAnalista(reunion);
            } else if (rolId === 6) {
                box.innerHTML = formularioKam(reunion);
            } else {
                return;
            }

            body.appendChild(box);
        }

        function adaptarReprogramacionActiva(reunion) {
            const estado = String(reunion.estado || '');

            if (rolId === 6 && estado === 'SOLICITADA') {
                const form = body.querySelector('[data-agenda-action="confirmar"]');
                const zoom = form?.querySelector('[name="zoom_url"]');
                if (zoom && !zoom.value && String(reunion.zoom_url || '').trim() !== '') {
                    zoom.value = String(reunion.zoom_url || '').trim();
                }

                const titulo = form?.closest('.agenda-action-box')?.querySelector('h6');
                const texto = form?.closest('.agenda-action-box')?.querySelector('p');
                if (titulo) {
                    titulo.textContent = 'Confirmar nueva fecha';
                }
                if (texto) {
                    texto.textContent = 'Revisa la nueva fecha. Puedes conservar el enlace de Zoom anterior o reemplazarlo antes de confirmar.';
                }
            }

            if (rolId === 4 && estado === 'CONFIRMADA') {
                prepararCorreoReprogramacion(reunion);
            }

            if (rolId === 4 && estado === 'CORREO_ENVIADO') {
                const titulo = body.querySelector('.agenda-action-box h6');
                const nota = body.querySelector('.agenda-inline-note.is-success');
                if (titulo && titulo.textContent.trim() === 'Reunión formalmente agendada') {
                    titulo.textContent = 'Reunión reprogramada';
                }
                if (nota) {
                    nota.innerHTML = '<i class="bi bi-check2-circle"></i> Reprogramación enviada a la institución.';
                }
            }
        }

        function prepararCorreoReprogramacion(reunion) {
            const form = body.querySelector('[data-agenda-action="marcarCorreoEnviado"]');
            if (!form || form.dataset.reprogramacionPreparada === '1') {
                return;
            }

            const asunto = form.querySelector('[name="asunto"]');
            const cuerpo = form.querySelector('[name="cuerpo"]');
            const institucion = texto(reunion.nombre_entidad, 'la institución');
            const contacto = texto(reunion.contacto_nombre, '');
            const saludo = contacto !== '' ? 'Estimado/a ' + contacto + ':' : 'Buen día:';
            const fecha = texto(reunion.fecha_legible, texto(reunion.fecha_propuesta, ''));
            const modalidad = etiquetaModalidad(reunion.modalidad);
            const lineas = [
                saludo,
                '',
                'Por este medio confirmamos la reprogramación de la reunión de vinculación con ' + institucion + '.',
                '',
                'Nueva fecha y hora: ' + fecha,
                'Modalidad: ' + modalidad
            ];

            if (texto(reunion.zoom_url, '') !== '') {
                lineas.push('Enlace de Zoom: ' + texto(reunion.zoom_url, ''));
            }
            if (texto(reunion.ubicacion, '') !== '') {
                lineas.push('Lugar: ' + texto(reunion.ubicacion, ''));
            }

            lineas.push('', 'Agradecemos su comprensión y quedamos atentos.', '', 'Saludos cordiales.');

            if (asunto) {
                asunto.value = 'Reprogramación de reunión de vinculación - ' + institucion;
            }
            if (cuerpo) {
                cuerpo.value = lineas.join('\n');
            }

            const encabezado = form.closest('.agenda-action-box')?.querySelector('h6');
            const descripcion = form.closest('.agenda-action-box')?.querySelector('p');
            if (encabezado) {
                encabezado.textContent = 'Nueva fecha confirmada';
            }
            if (descripcion) {
                descripcion.textContent = 'Cuenta Clave confirmó la reprogramación. Envía la nueva fecha a la institución para volver a completar el paso 11.';
            }

            form.dataset.reprogramacionPreparada = '1';
        }

        function formularioAnalista(reunion) {
            return '' +
                '<h6>¿Necesitas cambiar la fecha?</h6>' +
                '<p>Registra el motivo y propón una nueva fecha. La cita actual se conservará en el historial y Cuenta Clave deberá confirmar nuevamente.</p>' +
                '<form data-agenda-action-form data-agenda-action="solicitarReprogramacion">' +
                    '<input type="hidden" name="reunion_id" value="' + Number(reunion.id || 0) + '">' +
                    '<div class="row g-3">' +
                        '<div class="col-12">' +
                            '<label class="form-label">Motivo de la reprogramación</label>' +
                            '<textarea class="form-control system-form-control" name="motivo" rows="2" maxlength="4000" placeholder="Ej. La institución solicitó cambiar el horario..." required></textarea>' +
                        '</div>' +
                        '<div class="col-md-6">' +
                            '<label class="form-label">Nueva fecha y hora</label>' +
                            '<input class="form-control system-form-control" type="datetime-local" name="fecha_propuesta" required>' +
                        '</div>' +
                        '<div class="col-md-3">' +
                            '<label class="form-label">Duración</label>' +
                            selectDuracion(Number(reunion.duracion_minutos || 60)) +
                        '</div>' +
                        '<div class="col-md-3">' +
                            '<label class="form-label">Modalidad</label>' +
                            selectModalidad(String(reunion.modalidad || 'VIRTUAL')) +
                        '</div>' +
                    '</div>' +
                    '<div class="agenda-action-row">' +
                        '<button class="btn btn-system-light" type="submit"><i class="bi bi-calendar2-week"></i> Solicitar reprogramación</button>' +
                    '</div>' +
                '</form>';
        }

        function formularioKam(reunion) {
            return '' +
                '<h6>Solicitar reprogramación</h6>' +
                '<p>Si ya no puedes asistir en la fecha confirmada, indica el motivo. El Analista recibirá una notificación para proponer otro horario.</p>' +
                '<form data-agenda-action-form data-agenda-action="solicitarReprogramacionKam">' +
                    '<input type="hidden" name="reunion_id" value="' + Number(reunion.id || 0) + '">' +
                    '<textarea class="form-control system-form-control" name="motivo" rows="3" maxlength="4000" placeholder="Ej. El horario ya no está disponible; solicitar una nueva fecha..." required></textarea>' +
                    '<div class="agenda-action-row">' +
                        '<button class="btn btn-system-light" type="submit"><i class="bi bi-arrow-repeat"></i> Pedir nueva fecha</button>' +
                    '</div>' +
                '</form>';
        }

        function selectDuracion(actual) {
            return '<select class="form-select system-form-control" name="duracion_minutos" required>' +
                [30, 45, 60, 90, 120].map(function (valor) {
                    return '<option value="' + valor + '"' + (valor === actual ? ' selected' : '') + '>' + valor + ' min</option>';
                }).join('') +
            '</select>';
        }

        function selectModalidad(actual) {
            const opciones = [
                ['VIRTUAL', 'Virtual'],
                ['PRESENCIAL', 'Presencial'],
                ['HIBRIDA', 'Híbrida']
            ];
            return '<select class="form-select system-form-control" name="modalidad" required>' +
                opciones.map(function (opcion) {
                    return '<option value="' + opcion[0] + '"' + (opcion[0] === actual ? ' selected' : '') + '>' + opcion[1] + '</option>';
                }).join('') +
            '</select>';
        }

        function etiquetaModalidad(valor) {
            const mapa = {
                VIRTUAL: 'Virtual',
                PRESENCIAL: 'Presencial',
                HIBRIDA: 'Híbrida'
            };
            return mapa[String(valor || '').toUpperCase()] || 'Por definir';
        }

        function texto(valor, fallback) {
            const limpio = String(valor ?? '').trim();
            return limpio !== '' ? limpio : (fallback || '');
        }

        function leerJson(id) {
            const elemento = document.getElementById(id);
            if (!elemento) {
                return [];
            }
            try {
                const datos = JSON.parse(elemento.textContent || '[]');
                return Array.isArray(datos) ? datos : [];
            } catch (error) {
                console.error('Reprogramación: no fue posible leer los datos de agenda.', error);
                return [];
            }
        }
    });
})();
