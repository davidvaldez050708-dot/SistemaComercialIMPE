(function () {
    'use strict';

    let enviando = false;

    const mostrarToast = function (mensaje) {
        if (!window.bootstrap) {
            return;
        }

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
        bootstrap.Toast.getOrCreateInstance(toast, {
            autohide: true,
            delay: 3500
        }).show();
    };

    const mostrarErrorFormulario = function (form, mensaje) {
        const modal = form.closest('.modal');
        const errorSeguimiento = modal?.querySelector('[data-followup-mail-error]');

        if (errorSeguimiento) {
            errorSeguimiento.textContent = String(mensaje || 'No fue posible enviar el correo.');
            errorSeguimiento.classList.remove('d-none');
            return;
        }

        let error = form.querySelector('[data-signed-mail-error]');
        if (!error) {
            error = document.createElement('div');
            error.className = 'alert alert-danger mt-3';
            error.setAttribute('data-signed-mail-error', '');
            form.prepend(error);
        }
        error.textContent = String(mensaje || 'No fue posible enviar el correo.');
    };

    const limpiarError = function (form) {
        const modal = form.closest('.modal');
        const errorSeguimiento = modal?.querySelector('[data-followup-mail-error]');
        if (errorSeguimiento) {
            errorSeguimiento.classList.add('d-none');
            errorSeguimiento.textContent = '';
        }

        const error = form.querySelector('[data-signed-mail-error]');
        if (error) {
            error.remove();
        }
    };

    const botonEnvio = function (form) {
        return form.querySelector('[type="submit"]');
    };

    const seguimientoActual = function () {
        const offcanvas = document.getElementById('offcanvasSeguimientoTrabajo');
        return Number(offcanvas?.dataset.flowSeguimientoId || 0);
    };

    const enviarSeguimiento = async function (form) {
        const seguimientoId = seguimientoActual();
        if (seguimientoId <= 0) {
            mostrarErrorFormulario(form, 'No se pudo identificar el seguimiento.');
            return;
        }

        const datos = new FormData(form);
        datos.set('seguimiento_id', String(seguimientoId));
        await ejecutarEnvio(
            form,
            'index.php?controller=correoFirmado&action=enviarSeguimiento',
            datos,
            function (json) {
                const modal = form.closest('.modal');
                if (modal && window.bootstrap) {
                    bootstrap.Modal.getOrCreateInstance(modal).hide();
                }
                mostrarToast(json.mensaje || 'Correo de seguimiento enviado correctamente.');

                const offcanvas = document.getElementById('offcanvasSeguimientoTrabajo');
                const proxima = offcanvas?.querySelector('[data-work-next-action]');
                if (proxima) {
                    proxima.textContent = 'Continuar seguimiento por correo';
                }
            }
        );
    };

    const enviarReunion = async function (form) {
        const datos = new FormData(form);
        const reunionId = Number(datos.get('reunion_id') || 0);
        if (reunionId <= 0) {
            mostrarErrorFormulario(form, 'No se pudo identificar la reunión.');
            return;
        }

        const esReprogramacion = form.dataset.reprogramacionPreparada === '1' ||
            String(form.getAttribute('data-agenda-action') || '') === 'marcarCorreoReprogramacionEnviado';
        datos.set('reprogramacion', esReprogramacion ? '1' : '0');

        await ejecutarEnvio(
            form,
            'index.php?controller=correoFirmado&action=enviarReunion',
            datos,
            function (json) {
                mostrarToast(json.mensaje || 'Correo de reunión enviado correctamente.');
                window.setTimeout(function () {
                    window.location.href =
                        'index.php?controller=agendaReunion&action=index&reunion_id=' +
                        encodeURIComponent(reunionId);
                }, 500);
            }
        );
    };

    const ejecutarEnvio = async function (form, url, datos, alCompletar) {
        if (enviando) {
            return;
        }

        const boton = botonEnvio(form);
        const textoOriginal = boton ? boton.innerHTML : '';
        enviando = true;
        limpiarError(form);

        if (boton) {
            boton.disabled = true;
            boton.innerHTML = '<span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>Enviando...';
        }

        try {
            const respuesta = await fetch(url, {
                method: 'POST',
                body: datos,
                headers: { 'X-Requested-With': 'fetch' }
            });
            const json = await respuesta.json();

            if (!respuesta.ok || !json.ok) {
                mostrarErrorFormulario(form, json.mensaje || 'No fue posible enviar el correo.');
                return;
            }

            alCompletar(json);
        } catch (error) {
            console.error(error);
            mostrarErrorFormulario(form, 'No fue posible comunicarse con el sistema.');
        } finally {
            enviando = false;
            if (boton && document.body.contains(boton)) {
                boton.disabled = false;
                boton.innerHTML = textoOriginal;
            }
        }
    };

    const ajustarFormularioAgenda = function (form) {
        if (!form || form.dataset.signedMailPrepared === '1') {
            return;
        }

        const accion = String(form.getAttribute('data-agenda-action') || '');
        if (accion !== 'marcarCorreoEnviado' && accion !== 'marcarCorreoReprogramacionEnviado') {
            return;
        }

        const boton = botonEnvio(form);
        if (boton) {
            boton.innerHTML = '<i class="bi bi-send"></i> Enviar correo';
        }

        const nota = Array.from(form.querySelectorAll('.agenda-inline-note')).find(function (elemento) {
            return elemento.textContent.includes('Hostinger') ||
                elemento.textContent.includes('correo corporativo') ||
                elemento.textContent.includes('registra aquí');
        });

        if (nota) {
            nota.innerHTML =
                '<i class="bi bi-info-circle"></i> ' +
                'El correo se enviará desde el sistema. Si configuraste una firma en Mi perfil, se incluirá automáticamente.';
        }

        form.dataset.signedMailPrepared = '1';
    };

    const revisarFormulariosAgenda = function () {
        document.querySelectorAll('#modalAgendaDetalle [data-agenda-action-form]').forEach(function (form) {
            ajustarFormularioAgenda(form);
        });
    };

    document.addEventListener('submit', function (event) {
        const form = event.target;
        if (!(form instanceof HTMLFormElement)) {
            return;
        }

        if (form.matches('[data-followup-mail-form]')) {
            event.preventDefault();
            event.stopImmediatePropagation();
            enviarSeguimiento(form);
            return;
        }

        if (form.matches('#modalAgendaDetalle [data-agenda-action-form]')) {
            const accion = String(form.getAttribute('data-agenda-action') || '');
            const esCorreo = accion === 'marcarCorreoEnviado' ||
                accion === 'marcarCorreoReprogramacionEnviado' ||
                form.dataset.reprogramacionPreparada === '1';

            if (esCorreo && form.querySelector('[name="asunto"]') && form.querySelector('[name="cuerpo"]')) {
                event.preventDefault();
                event.stopImmediatePropagation();
                enviarReunion(form);
            }
        }
    }, true);

    document.addEventListener('DOMContentLoaded', function () {
        revisarFormulariosAgenda();

        const bodyAgenda = document.querySelector('#modalAgendaDetalle [data-agenda-detail-body]');
        if (!bodyAgenda) {
            return;
        }

        const observer = new MutationObserver(revisarFormulariosAgenda);
        observer.observe(bodyAgenda, {
            childList: true,
            subtree: true,
            attributes: true,
            attributeFilter: ['data-agenda-action', 'data-reprogramacion-preparada']
        });
    });
})();
