(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        const modal = document.getElementById('modalAgendaDetalle');
        const body = modal?.querySelector('[data-agenda-detail-body]');
        const reuniones = leerJson('agendaReunionesData');

        if (!modal || !body || !Array.isArray(reuniones) || reuniones.length === 0) {
            return;
        }

        const reunionesPorId = new Map(
            reuniones.map(function (item) {
                return [Number(item.id || 0), item];
            })
        );

        const observer = new MutationObserver(function () {
            prepararCorreoVisible();
        });

        observer.observe(body, {
            childList: true,
            subtree: true
        });

        modal.addEventListener('shown.bs.modal', function () {
            window.setTimeout(prepararCorreoVisible, 0);
        });

        document.addEventListener('click', function (event) {
            if (!event.target.closest('[data-agenda-meeting]')) {
                return;
            }

            window.setTimeout(prepararCorreoVisible, 0);
        }, true);

        function prepararCorreoVisible() {
            const formularios = body.querySelectorAll(
                'form[data-agenda-action-form]'
            );

            formularios.forEach(function (form) {
                const asunto = form.querySelector('[name="asunto"]');
                const cuerpo = form.querySelector('[name="cuerpo"]');
                const reunionId = Number(
                    form.querySelector('[name="reunion_id"]')?.value || 0
                );
                const reunion = reunionesPorId.get(reunionId);

                if (!asunto || !cuerpo || !reunion) {
                    return;
                }

                const esReprogramacion =
                    Number(reunion.es_reprogramacion || 0) === 1 ||
                    String(form.getAttribute('data-agenda-action') || '')
                        .toLowerCase()
                        .includes('reprogram');
                const token = [
                    reunionId,
                    String(reunion.estado || ''),
                    esReprogramacion ? 'R' : 'N'
                ].join(':');

                if (form.dataset.correoNaturalPreparado === token) {
                    return;
                }

                const mensaje = construirMensaje(reunion, esReprogramacion);

                if (mensaje !== '') {
                    cuerpo.value = mensaje;
                }

                form.dataset.correoNaturalPreparado = token;
            });
        }

        function construirMensaje(reunion, esReprogramacion) {
            const fecha = descomponerFecha(reunion.fecha_propuesta);

            if (!fecha) {
                return '';
            }

            const institucion = texto(reunion.nombre_entidad, 'la institución');
            const objetivo = texto(reunion.objetivo, 'Reunión de vinculación');
            const zoom = texto(reunion.zoom_url, '');
            const ubicacion = texto(reunion.ubicacion, '');
            const modalidad = String(reunion.modalidad || '').toUpperCase();
            const fechaCorta = fecha.diaSemana + ' ' + fecha.dia;
            const apertura = esReprogramacion
                ? 'Buenas tardes. Confirmamos la nueva fecha de la cita para el ' +
                    fechaCorta + ' a las ' + fecha.hora + '.'
                : 'Buenas tardes. Confirmamos la cita para el ' +
                    fechaCorta + ' a las ' + fecha.hora + '.';
            const lineas = [apertura];

            if (zoom !== '') {
                lineas.push('Le comparto la liga de Zoom.');
            } else if (ubicacion !== '' && modalidad !== 'VIRTUAL') {
                lineas.push('Le comparto los datos de la reunión.');
            }

            lineas.push(
                '',
                'Muchas gracias, ¡saludos!',
                '',
                'Tema: ' + objetivo,
                fecha.fechaCompleta,
                'Horario: ' + fecha.hora
            );

            if (zoom !== '') {
                lineas.push(
                    '',
                    'Únase a la reunión de Zoom',
                    zoom
                );
            } else if (ubicacion !== '' && modalidad !== 'VIRTUAL') {
                lineas.push('', 'Lugar: ' + ubicacion);
            }

            return lineas.join('\n');
        }

        function descomponerFecha(valor) {
            const limpio = String(valor || '').trim();
            const match = limpio.match(
                /^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})/
            );

            if (!match) {
                return null;
            }

            const anio = Number(match[1]);
            const mes = Number(match[2]);
            const dia = Number(match[3]);
            const hora24 = Number(match[4]);
            const minuto = Number(match[5]);
            const fecha = new Date(anio, mes - 1, dia, hora24, minuto, 0);
            const dias = [
                'domingo', 'lunes', 'martes', 'miércoles',
                'jueves', 'viernes', 'sábado'
            ];
            const meses = [
                'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio',
                'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'
            ];
            const periodo = hora24 < 12 ? 'a. m.' : 'p. m.';
            let hora12 = hora24 % 12;

            if (hora12 === 0) {
                hora12 = 12;
            }

            const minutoTexto = String(minuto).padStart(2, '0');

            return {
                diaSemana: dias[fecha.getDay()],
                dia: dia,
                fechaCompleta: dia + ' de ' + meses[mes - 1] + ' de ' + anio,
                hora: hora12 + ':' + minutoTexto + ' ' + periodo
            };
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
                console.error(
                    'Agenda: no fue posible preparar el correo de reunión.',
                    error
                );
                return [];
            }
        }
    });
})();
