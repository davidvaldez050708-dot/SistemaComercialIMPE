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

            let bloque = contenedor.querySelector('[data-reunion-followup-date]');
            const requiere = select.value === 'REQUIERE_SEGUIMIENTO';

            if (!requiere) {
                bloque?.remove();
                return;
            }

            if (bloque) {
                return;
            }

            bloque = document.createElement('div');
            bloque.className = 'mt-3';
            bloque.setAttribute('data-reunion-followup-date', '');
            bloque.innerHTML =
                '<label class="form-label">Próximo seguimiento</label>' +
                '<input class="form-control" type="datetime-local" name="reunion_seguimiento_fecha" required>' +
                '<div class="form-text">La reunión quedará registrada como realizada, pero el caso permanecerá en el paso 12 hasta atender este seguimiento.</div>';

            const ayuda = contenedor.querySelector('[data-reunion-no-realizada-help]');
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
                    const bloque = modal.querySelector('[data-followup-next-date]');
                    const input = bloque?.querySelector('[name="seguimiento_reunion_fecha"]');
                    const requiere = event.target.value === 'REQUIERE_SEGUIMIENTO';

                    bloque?.classList.toggle('d-none', !requiere);
                    if (input) {
                        input.required = requiere;
                        if (!requiere) {
                            input.value = '';
                        }
                    }
                });

            modal.querySelector('[data-reunion-followup-form]')
                ?.addEventListener('submit', guardarSeguimiento);

            return modal;
        };

        const abrirSeguimiento = function () {
            if (seguimientoActualId <= 0) {
                return;
            }

            const modal = asegurarModalSeguimiento();
            const form = modal.querySelector('[data-reunion-followup-form]');
            const error = modal.querySelector('[data-reunion-followup-error]');
            const fechaBloque = modal.querySelector('[data-followup-next-date]');
            const fechaInput = fechaBloque?.querySelector('[name="seguimiento_reunion_fecha"]');

            form?.reset();
            error?.classList.add('d-none');
            if (error) {
                error.textContent = '';
            }
            fechaBloque?.classList.add('d-none');
            if (fechaInput) {
                fechaInput.required = false;
            }

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
