(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        const parametros = new URLSearchParams(window.location.search);
        const esDetalle =
            parametros.get('controller') === 'seguimientoVinculacion' &&
            parametros.get('action') === 'detalle';
        const seguimientoDetalleId = Number(parametros.get('id') || 0);
        const urlEstado = 'index.php?controller=oficioCorreo&action=programacion';
        const urlProgramar = 'index.php?controller=oficioCorreo&action=programarEnvio';
        let seguimientoTrabajoId = 0;
        let seguimientoModalId = 0;
        let estadoActual = null;

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

        const fechaLocalInput = function (fecha) {
            const valor = fecha instanceof Date ? fecha : new Date(fecha);
            const local = new Date(valor.getTime() - (valor.getTimezoneOffset() * 60000));
            return local.toISOString().slice(0, 16);
        };

        const crearModal = function () {
            let modal = document.getElementById('modalProgramarEnvioOficio');

            if (modal) {
                return modal;
            }

            modal = document.createElement('div');
            modal.className = 'modal fade';
            modal.id = 'modalProgramarEnvioOficio';
            modal.tabIndex = -1;
            modal.setAttribute('aria-labelledby', 'modalProgramarEnvioOficioTitulo');
            modal.setAttribute('aria-hidden', 'true');
            modal.innerHTML =
                '<div class="modal-dialog modal-dialog-centered system-form-dialog">' +
                    '<div class="modal-content system-form-modal">' +
                        '<div class="modal-header system-form-modal-header">' +
                            '<div>' +
                                '<h5 class="system-form-modal-title" id="modalProgramarEnvioOficioTitulo">Programar envío</h5>' +
                                '<p class="system-form-modal-subtitle">Define cuándo debe realizarse el envío del oficio y correo.</p>' +
                            '</div>' +
                            '<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>' +
                        '</div>' +
                        '<div class="modal-body">' +
                            '<div class="alert alert-info mb-3">' +
                                '<i class="bi bi-bell me-2"></i>' +
                                'Esta programación crea el recordatorio, pero todavía no envía ningún correo.' +
                            '</div>' +
                            '<label class="form-label" for="oficio_schedule_at">Fecha y hora de envío *</label>' +
                            '<input class="form-control" id="oficio_schedule_at" type="datetime-local" required data-schedule-input>' +
                            '<div class="form-text mt-2">Podrás cambiarla después con Reprogramar.</div>' +
                        '</div>' +
                        '<div class="modal-footer system-form-modal-footer">' +
                            '<button type="button" class="btn btn-system-cancel" data-bs-dismiss="modal">Cancelar</button>' +
                            '<button type="button" class="btn btn-system-save" data-schedule-save>' +
                                '<i class="bi bi-calendar-check me-2"></i>Guardar programación' +
                            '</button>' +
                        '</div>' +
                    '</div>' +
                '</div>';
            document.body.appendChild(modal);

            return modal;
        };

        const crearBloque = function (tipo) {
            const bloque = document.createElement('div');
            bloque.className = tipo === 'work'
                ? 'border-top pt-3 mt-3'
                : 'border rounded-3 p-3 mt-3';
            bloque.setAttribute(
                tipo === 'work' ? 'data-work-send-schedule' : 'data-detail-send-schedule',
                ''
            );
            bloque.innerHTML =
                '<div class="d-flex align-items-start justify-content-between gap-3 flex-wrap">' +
                    '<div>' +
                        '<span class="d-block text-muted small">ENVÍO PROGRAMADO</span>' +
                        '<strong data-schedule-label>Sin programar</strong>' +
                        '<span class="d-block text-muted small mt-1 d-none" data-schedule-note></span>' +
                    '</div>' +
                    '<button type="button" class="btn btn-system-light" data-schedule-open>' +
                        '<i class="bi bi-calendar-event me-1"></i>Programar envío' +
                    '</button>' +
                '</div>';
            return bloque;
        };

        const obtenerBloqueTrabajo = function () {
            const seccion = document.querySelector('[data-work-oficio-section]');

            if (!seccion) {
                return null;
            }

            let bloque = seccion.querySelector('[data-work-send-schedule]');

            if (!bloque) {
                bloque = crearBloque('work');
                seccion.appendChild(bloque);
            }

            return bloque;
        };

        const obtenerBloqueDetalle = function () {
            if (!esDetalle || seguimientoDetalleId <= 0) {
                return null;
            }

            const contenedor = document.querySelector('[data-detail-oficio-action]');

            if (!contenedor) {
                return null;
            }

            let bloque = contenedor.querySelector('[data-detail-send-schedule]');

            if (!bloque) {
                bloque = crearBloque('detail');
                contenedor.appendChild(bloque);
            }

            return bloque;
        };

        const renderizarBloque = function (bloque, programacion) {
            if (!bloque || !programacion) {
                return;
            }

            const programado = Boolean(programacion.programado);
            const puedeProgramar = Boolean(programacion.puede_programar);
            const soloConsulta = Boolean(programacion.solo_consulta);
            const cumple = Boolean(programacion.cumple_requisitos);
            const mostrar = programado || cumple;
            const etiqueta = bloque.querySelector('[data-schedule-label]');
            const nota = bloque.querySelector('[data-schedule-note]');
            const boton = bloque.querySelector('[data-schedule-open]');

            bloque.classList.toggle('d-none', !mostrar);

            if (!mostrar) {
                return;
            }

            if (etiqueta) {
                etiqueta.textContent = programado
                    ? String(programacion.proxima_accion_label || 'Programado')
                    : 'Sin programar';
            }

            if (nota) {
                const texto = soloConsulta
                    ? (programado
                        ? 'Programado por el Analista responsable.'
                        : 'Pendiente de programación por el Analista responsable.')
                    : 'Generará un recordatorio para Enviar oficio/correo.';
                nota.textContent = texto;
                nota.classList.remove('d-none');
            }

            if (boton) {
                boton.classList.toggle('d-none', !puedeProgramar);
                boton.innerHTML =
                    '<i class="bi bi-calendar-event me-1"></i>' +
                    (programado ? 'Reprogramar' : 'Programar envío');
            }
        };

        const renderizar = function (programacion) {
            estadoActual = programacion || null;
            renderizarBloque(obtenerBloqueTrabajo(), programacion);
            renderizarBloque(obtenerBloqueDetalle(), programacion);
        };

        const consultar = async function (seguimientoId) {
            if (!seguimientoId) {
                return null;
            }

            const respuesta = await fetch(
                urlEstado + '&seguimiento_id=' + encodeURIComponent(seguimientoId),
                {
                    headers: { 'X-Requested-With': 'fetch' },
                    cache: 'no-store'
                }
            );
            const datos = await respuesta.json();

            if (!datos.ok || !datos.programacion) {
                return null;
            }

            renderizar(datos.programacion);
            return datos.programacion;
        };

        const abrirModal = function (seguimientoId) {
            if (!seguimientoId || !estadoActual?.puede_programar) {
                return;
            }

            seguimientoModalId = seguimientoId;
            const modal = crearModal();
            const campo = modal.querySelector('[data-schedule-input]');
            const ahora = new Date();
            ahora.setMinutes(ahora.getMinutes() + 5);
            const valorInicial = estadoActual?.proxima_accion_input || fechaLocalInput(ahora);
            const minimo = new Date();
            minimo.setMinutes(minimo.getMinutes() + 1);

            campo.min = fechaLocalInput(minimo);
            campo.value = valorInicial;
            bootstrap.Modal.getOrCreateInstance(modal).show();
        };

        const guardar = async function (boton) {
            const modal = crearModal();
            const campo = modal.querySelector('[data-schedule-input]');
            const fecha = String(campo?.value || '').trim();

            if (!seguimientoModalId || fecha === '') {
                campo?.reportValidity();
                campo?.focus();
                return;
            }

            const htmlOriginal = boton.innerHTML;
            boton.disabled = true;
            boton.innerHTML =
                '<span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>' +
                'Guardando...';

            const formulario = new FormData();
            formulario.append('seguimiento_id', String(seguimientoModalId));
            formulario.append('proxima_accion_at', fecha);

            try {
                const respuesta = await fetch(urlProgramar, {
                    method: 'POST',
                    headers: { 'X-Requested-With': 'fetch' },
                    body: formulario
                });
                const datos = await respuesta.json();

                if (!datos.ok || !datos.programacion) {
                    throw new Error(
                        datos.mensaje || 'No fue posible guardar la programación.'
                    );
                }

                renderizar(datos.programacion);
                bootstrap.Modal.getOrCreateInstance(modal).hide();
                mostrarToast(datos.mensaje || 'Programación guardada correctamente.', false);
                document.dispatchEvent(new CustomEvent('recordatorios:actualizar'));
            } catch (error) {
                console.error(error);
                mostrarToast(
                    error.message || 'No fue posible guardar la programación.',
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
                window.setTimeout(function () {
                    consultar(seguimientoTrabajoId).catch(console.error);
                }, 140);
                return;
            }

            const botonAbrir = event.target.closest('[data-schedule-open]');

            if (botonAbrir) {
                event.preventDefault();
                const esTrabajo = Boolean(botonAbrir.closest('[data-work-send-schedule]'));
                abrirModal(esTrabajo ? seguimientoTrabajoId : seguimientoDetalleId);
                return;
            }

            const botonGuardar = event.target.closest('[data-schedule-save]');

            if (botonGuardar) {
                event.preventDefault();
                guardar(botonGuardar);
            }
        });

        document
            .getElementById('offcanvasSeguimientoTrabajo')
            ?.addEventListener('hidden.bs.offcanvas', function () {
                seguimientoTrabajoId = 0;
                estadoActual = null;
                document.querySelector('[data-work-send-schedule]')?.remove();
            });

        crearModal().addEventListener('hidden.bs.modal', function () {
            seguimientoModalId = 0;
        });

        const estadoCorreo = document.querySelector('[data-mail-status]');
        if (estadoCorreo && window.MutationObserver) {
            const observadorCorreo = new MutationObserver(function () {
                if (
                    seguimientoTrabajoId > 0 &&
                    String(estadoCorreo.textContent || '').includes('Borrador guardado')
                ) {
                    consultar(seguimientoTrabajoId).catch(console.error);
                }

                if (
                    esDetalle &&
                    seguimientoDetalleId > 0 &&
                    String(estadoCorreo.textContent || '').includes('Borrador guardado')
                ) {
                    consultar(seguimientoDetalleId).catch(console.error);
                }
            });
            observadorCorreo.observe(estadoCorreo, {
                childList: true,
                characterData: true,
                subtree: true
            });
        }

        if (esDetalle && seguimientoDetalleId > 0) {
            window.setTimeout(function () {
                consultar(seguimientoDetalleId).catch(console.error);
            }, 180);
        }
    });
})();
