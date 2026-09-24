(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        const offcanvas = document.getElementById('offcanvasSeguimientoTrabajo');

        if (!offcanvas || !window.bootstrap) {
            return;
        }

        const endpoint = 'index.php?controller=seguimientoFlujo&action=registrarPostEnvio';
        let seguimientoActualId = 0;

        const escapar = function (valor) {
            const div = document.createElement('div');
            div.textContent = String(valor || '');
            return div.innerHTML;
        };

        const mostrarToast = function (mensaje) {
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
            toast.addEventListener('hidden.bs.toast', function () {
                toast.remove();
            });
            bootstrap.Toast.getOrCreateInstance(toast, {
                autohide: true,
                delay: 3200
            }).show();
        };

        const actualizarRuta = function () {
            const proxima = offcanvas.querySelector('[data-work-next-action]');
            if (proxima) {
                proxima.textContent = 'Actualizando ruta...';
            }
        };

        const asegurarAyudaReunion = function () {
            const modal = document.getElementById('modalSeguimientoPostEnvio');
            const select = modal?.querySelector('[name="reunion_resultado"]');
            const contenedor = modal?.querySelector('[data-post-envio-fields]');

            if (!modal || !select || !contenedor) {
                return;
            }

            if (!contenedor.querySelector('[data-reunion-no-realizada-help]')) {
                const ayuda = document.createElement('div');
                ayuda.className = 'alert alert-light border mt-3 mb-0';
                ayuda.setAttribute('data-reunion-no-realizada-help', '');
                ayuda.innerHTML =
                    '<div class="d-flex gap-2 align-items-start">' +
                        '<i class="bi bi-calendar-x text-secondary"></i>' +
                        '<div>' +
                            '<strong class="d-block mb-1">¿La reunión no se realizó?</strong>' +
                            '<span class="small text-muted">No la registres como realizada. Cierra este formulario y usa <strong>Ver / reprogramar</strong> para proponer una nueva fecha.</span>' +
                        '</div>' +
                    '</div>';
                contenedor.appendChild(ayuda);
            }

            actualizarCampoFechaReunion(select);
        };

        const actualizarCampoFechaReunion = function (select) {
            const modal = select.closest('#modalSeguimientoPostEnvio');
            const contenedor = modal?.querySelector('[data-post-envio-fields]');
            if (!contenedor) {
                return;
            }

            let bloque = contenedor.querySelector(
                '[data-reunion-seguimiento-fecha]'
            );
            const bloqueFijo = Boolean(bloque);

            if (!bloque) {
                bloque = contenedor.querySelector(
                    '[data-reunion-followup-date]'
                );
            }

            const requiere = select.value === 'REQUIERE_SEGUIMIENTO';

            if (!requiere) {
                if (bloqueFijo && bloque) {
                    bloque.classList.add('d-none');
                    const input = bloque.querySelector(
                        '[name="reunion_seguimiento_fecha"]'
                    );
                    if (input) {
                        input.required = false;
                        input.value = '';
                    }
                } else {
                    bloque?.remove();
                }
                return;
            }

            if (bloqueFijo && bloque) {
                bloque.classList.remove('d-none');
                const input = bloque.querySelector(
                    '[name="reunion_seguimiento_fecha"]'
                );
                if (input) {
                    input.required = true;
                }
                return;
            }

            if (bloque) {
                return;
            }

            /*
             * Compatibilidad con vistas anteriores que todavía no incluyen
             * el campo dentro de camposReunionRealizada().
             */
            bloque = document.createElement('div');
            bloque.className = 'mt-3';
            bloque.setAttribute('data-reunion-followup-date', '');
            bloque.innerHTML =
                '<label class="form-label">Dar seguimiento el</label>' +
                '<input class="form-control" type="datetime-local" name="reunion_seguimiento_fecha" required>';

            const ayuda = contenedor.querySelector(
                '[data-reunion-no-realizada-help]'
            );
            if (ayuda) {
                ayuda.insertAdjacentElement('beforebegin', bloque);
            } else {
                contenedor.appendChild(bloque);
            }
        };

        const asegurarModalSeguimiento = function () {
            let modal = document.getElementById('modalSeguimientoAcuerdos');
            if (modal) {
                return modal;
            }

            modal = document.createElement('div');
            modal.className = 'modal fade';
            modal.id = 'modalSeguimientoAcuerdos';
            modal.tabIndex = -1;
            modal.setAttribute('aria-hidden', 'true');
            modal.innerHTML =
                '<div class="modal-dialog modal-dialog-centered modal-lg">' +
                    '<div class="modal-content system-form-modal">' +
                        '<form data-reunion-followup-form>' +
                            '<div class="modal-header">' +
                                '<div>' +
                                    '<h5 class="modal-title">Seguimiento de acuerdos</h5>' +
                                    '<p class="modal-subtitle mb-0">Registra qué ocurrió después de la reunión y define el siguiente paso.</p>' +
                                '</div>' +
                                '<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>' +
                            '</div>' +
                            '<div class="modal-body">' +
                                '<div class="alert alert-danger d-none" data-reunion-followup-error></div>' +
                                '<div class="reunion-followup-context d-none" data-followup-current-context>' +
                                    '<span class="reunion-followup-context-kicker">Pendiente acordado en la reunión</span>' +
                                    '<strong data-followup-current-objective>—</strong>' +
                                    '<div class="reunion-followup-context-meta">' +
                                        '<span><b>Acción:</b> <span data-followup-current-action>—</span></span>' +
                                        '<span><b>Pendiente de:</b> <span data-followup-current-owner>—</span></span>' +
                                    '</div>' +
                                '</div>' +
                                '<div class="row g-3">' +
                                    '<div class="col-md-6">' +
                                        '<label class="form-label">Resultado del seguimiento</label>' +
                                        '<select class="form-select" name="seguimiento_reunion_resultado" required>' +
                                            '<option value="">Selecciona una opción</option>' +
                                            '<option value="AVANZAR_CONVENIO">Listo para avanzar a convenio</option>' +
                                            '<option value="REQUIERE_SEGUIMIENTO">Requiere otro seguimiento</option>' +
                                            '<option value="NO_INTERESADO">No continuará el proceso</option>' +
                                        '</select>' +
                                    '</div>' +
                                    '<div class="col-md-6 d-none" data-followup-next-date>' +
                                        '<label class="form-label">Nueva fecha de seguimiento</label>' +
                                        '<input class="form-control" type="datetime-local" name="seguimiento_reunion_fecha">' +
                                    '</div>' +
                                    '<div class="col-12 d-none" data-followup-next-context>' +
                                        '<div class="reunion-followup-plan">' +
                                            '<div class="reunion-followup-plan-heading">' +
                                                '<strong>Definir el siguiente pendiente</strong>' +
                                                '<span>Actualiza qué deberá resolverse antes de la nueva revisión.</span>' +
                                            '</div>' +
                                            '<div class="row g-3">' +
                                                '<div class="col-12">' +
                                                    '<label class="form-label">Pendiente acordado</label>' +
                                                    '<textarea class="form-control" name="seguimiento_reunion_objetivo" rows="2" maxlength="1200" placeholder="Ej. La institución enviará la autorización interna para continuar."></textarea>' +
                                                '</div>' +
                                                '<div class="col-md-6">' +
                                                    '<label class="form-label">Pendiente de</label>' +
                                                    '<select class="form-select" name="seguimiento_reunion_pendiente_de">' +
                                                        '<option value="">Selecciona una opción</option>' +
                                                        '<option value="INSTITUCION">Institución</option>' +
                                                        '<option value="FUNDACION">Equipo interno</option>' +
                                                        '<option value="AMBOS">Ambos</option>' +
                                                    '</select>' +
                                                '</div>' +
                                                '<div class="col-md-6">' +
                                                    '<label class="form-label">Acción prevista</label>' +
                                                    '<select class="form-select" name="seguimiento_reunion_accion">' +
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
                                        '<label class="form-label">Resultado y acuerdos</label>' +
                                        '<textarea class="form-control" name="seguimiento_reunion_notas" rows="5" maxlength="5000" placeholder="Ej. Se revisaron los pendientes, la institución confirmó que continuará con el proceso..." required></textarea>' +
                                    '</div>' +
                                '</div>' +
                            '</div>' +
                            '<div class="modal-footer">' +
                                '<button type="button" class="btn btn-system-light" data-bs-dismiss="modal">Cancelar</button>' +
                                '<button type="submit" class="btn btn-system-save" data-reunion-followup-save>' +
                                    '<i class="bi bi-check2-circle"></i> Guardar seguimiento' +
                                '</button>' +
                            '</div>' +
                        '</form>' +
                    '</div>' +
                '</div>';
            document.body.appendChild(modal);

            modal.querySelector('[name="seguimiento_reunion_resultado"]')
                ?.addEventListener('change', function (event) {
                    const bloque = modal.querySelector(
                        '[data-followup-next-date]'
                    );
                    const input = bloque?.querySelector(
                        '[name="seguimiento_reunion_fecha"]'
                    );
                    const contexto = modal.querySelector(
                        '[data-followup-next-context]'
                    );
                    const camposContexto = contexto
                        ? contexto.querySelectorAll(
                            'input, textarea, select'
                        )
                        : [];
                    const requiere =
                        event.target.value === 'REQUIERE_SEGUIMIENTO';

                    bloque?.classList.toggle('d-none', !requiere);
                    contexto?.classList.toggle('d-none', !requiere);

                    if (input) {
                        input.required = requiere;
                        if (requiere) {
                            const minimo = new Date(
                                Date.now() + (5 * 60 * 1000)
                            );
                            const offset = minimo.getTimezoneOffset();
                            const local = new Date(
                                minimo.getTime() - offset * 60000
                            );
                            input.min = local.toISOString().slice(0, 16);
                        } else {
                            input.value = '';
                            input.removeAttribute('min');
                        }
                    }

                    camposContexto.forEach(function (campo) {
                        campo.required = requiere;
                        if (!requiere) {
                            campo.value = '';
                        }
                    });
                });

            modal.querySelector('[data-reunion-followup-form]')
                ?.addEventListener('submit', guardarSeguimiento);

            return modal;
        };

        const cargarContextoSeguimiento = async function (modal) {
            const tarjeta = modal.querySelector(
                '[data-followup-current-context]'
            );

            if (!tarjeta || seguimientoActualId <= 0) {
                return;
            }

            tarjeta.classList.add('d-none');

            try {
                const respuesta = await fetch(
                    'index.php?controller=seguimientoFlujo&action=estado&seguimiento_id=' +
                    encodeURIComponent(seguimientoActualId),
                    {
                        headers: {
                            'X-Requested-With': 'fetch'
                        }
                    }
                );
                const json = await respuesta.json();

                if (!respuesta.ok || !json.ok) {
                    return;
                }

                const contexto = json.flujo?.contexto || {};
                const objetivo = String(
                    contexto.seguimiento_reunion_objetivo || ''
                ).trim();
                const accion = String(
                    contexto.seguimiento_reunion_accion_label || ''
                ).trim();
                const pendiente = String(
                    contexto.seguimiento_reunion_pendiente_de_label || ''
                ).trim();

                if (objetivo === '' && accion === '' && pendiente === '') {
                    return;
                }

                const objetivoEl = tarjeta.querySelector(
                    '[data-followup-current-objective]'
                );
                const accionEl = tarjeta.querySelector(
                    '[data-followup-current-action]'
                );
                const pendienteEl = tarjeta.querySelector(
                    '[data-followup-current-owner]'
                );

                if (objetivoEl) objetivoEl.textContent = objetivo || '—';
                if (accionEl) accionEl.textContent = accion || '—';
                if (pendienteEl) pendienteEl.textContent = pendiente || '—';

                tarjeta.classList.remove('d-none');
            } catch (error) {
                console.error(error);
            }
        };

        const abrirSeguimiento = async function () {
            if (seguimientoActualId <= 0) {
                return;
            }

            const modal = asegurarModalSeguimiento();
            const form = modal.querySelector('[data-reunion-followup-form]');
            const error = modal.querySelector('[data-reunion-followup-error]');
            const fechaBloque = modal.querySelector('[data-followup-next-date]');
            const fechaInput = fechaBloque?.querySelector('[name="seguimiento_reunion_fecha"]');
            const contextoSiguiente = modal.querySelector(
                '[data-followup-next-context]'
            );

            form?.reset();
            error?.classList.add('d-none');
            if (error) {
                error.textContent = '';
            }
            fechaBloque?.classList.add('d-none');
            contextoSiguiente?.classList.add('d-none');
            if (fechaInput) {
                fechaInput.required = false;
                fechaInput.removeAttribute('min');
            }
            contextoSiguiente
                ?.querySelectorAll('input, textarea, select')
                .forEach(function (campo) {
                    campo.required = false;
                });

            await cargarContextoSeguimiento(modal);
            bootstrap.Modal.getOrCreateInstance(modal).show();
        };

        async function guardarSeguimiento(event) {
            event.preventDefault();

            if (seguimientoActualId <= 0) {
                return;
            }

            const modal = asegurarModalSeguimiento();
            const form = event.currentTarget;
            const boton = modal.querySelector('[data-reunion-followup-save]');
            const error = modal.querySelector('[data-reunion-followup-error]');
            const datos = new FormData(form);
            datos.set('seguimiento_id', String(seguimientoActualId));
            datos.set('accion', 'REGISTRAR_SEGUIMIENTO_REUNION');

            boton.disabled = true;
            error.classList.add('d-none');

            try {
                const respuesta = await fetch(endpoint, {
                    method: 'POST',
                    body: datos,
                    headers: { 'X-Requested-With': 'fetch' }
                });
                const json = await respuesta.json();

                if (!respuesta.ok || !json.ok) {
                    error.textContent = json.mensaje || 'No fue posible guardar el seguimiento.';
                    error.classList.remove('d-none');
                    return;
                }

                bootstrap.Modal.getOrCreateInstance(modal).hide();
                mostrarToast(json.mensaje || 'Seguimiento registrado correctamente.');
                actualizarRuta();
            } catch (errorPeticion) {
                console.error(errorPeticion);
                error.textContent = 'No fue posible comunicarse con el sistema.';
                error.classList.remove('d-none');
            } finally {
                boton.disabled = false;
            }
        }

        document.addEventListener('change', function (event) {
            if (
                event.target.matches('#modalSeguimientoPostEnvio [name="reunion_resultado"]')
            ) {
                actualizarCampoFechaReunion(event.target);
            }
        });

        document.addEventListener('click', function (event) {
            const botonTrabajo = event.target.closest('[data-work-follow]');
            if (botonTrabajo) {
                seguimientoActualId = Number(
                    botonTrabajo.getAttribute('data-work-follow-id') || 0
                );
            }

            const registrarReunion = event.target.closest(
                '[data-flow-action="REGISTRAR_REUNION_REALIZADA"]'
            );
            if (registrarReunion && registrarReunion.closest('[data-work-flow-section]')) {
                window.setTimeout(asegurarAyudaReunion, 40);
                window.setTimeout(asegurarAyudaReunion, 140);
            }

            const seguimiento = event.target.closest(
                '[data-flow-action="REGISTRAR_SEGUIMIENTO_REUNION"]'
            );
            if (!seguimiento || !seguimiento.closest('[data-work-flow-section]')) {
                return;
            }

            event.preventDefault();
            event.stopImmediatePropagation();
            abrirSeguimiento();
        }, true);

        offcanvas.addEventListener('hidden.bs.offcanvas', function () {
            seguimientoActualId = 0;
        });
    });
})();
