(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        const modal = document.querySelector('[data-my-profile-modal]');
        if (!modal) {
            return;
        }

        const archivo = modal.querySelector('[data-mail-signature-file]');
        const preview = modal.querySelector('[data-mail-signature-preview]');
        const estado = modal.querySelector('[data-mail-signature-status]');
        const guardar = modal.querySelector('[data-mail-signature-save]');
        const eliminar = modal.querySelector('[data-mail-signature-delete]');
        const confirmacion = document.querySelector(
            '[data-mail-signature-confirm-modal]'
        );
        const confirmarEliminar = confirmacion
            ? confirmacion.querySelector(
                '[data-mail-signature-confirm-delete]'
            )
            : null;
        const errorConfirmacion = confirmacion
            ? confirmacion.querySelector(
                '[data-mail-signature-confirm-error]'
            )
            : null;
        const modalPerfil = window.bootstrap
            ? bootstrap.Modal.getOrCreateInstance(modal)
            : null;
        const modalConfirmacion =
            window.bootstrap && confirmacion
                ? bootstrap.Modal.getOrCreateInstance(confirmacion)
                : null;
        let firmaDisponible = false;
        let reabrirPerfil = false;
        let eliminandoFirma = false;

        if (
            !archivo ||
            !preview ||
            !estado ||
            !guardar ||
            !eliminar ||
            !confirmacion ||
            !confirmarEliminar ||
            !modalPerfil ||
            !modalConfirmacion
        ) {
            return;
        }

        const mostrarPreview = function (url) {
            preview.replaceChildren();

            if (!url) {
                const vacio = document.createElement('span');
                vacio.className = 'text-muted small';
                vacio.textContent = 'Sin firma configurada';
                preview.appendChild(vacio);
                return;
            }

            const imagen = document.createElement('img');
            imagen.src = url;
            imagen.alt = 'Vista previa de la firma de correo';
            imagen.style.maxWidth = '100%';
            imagen.style.maxHeight = '88px';
            imagen.style.objectFit = 'contain';
            imagen.style.display = 'block';
            preview.appendChild(imagen);
        };

        const actualizarEstado = function (firma) {
            firmaDisponible = Boolean(firma && firma.disponible);
            mostrarPreview(firmaDisponible ? String(firma.imagen_url || '') : '');
            estado.textContent = firmaDisponible
                ? 'Firma configurada. Se agregará automáticamente a los correos enviados desde el sistema.'
                : 'Aún no tienes una firma de correo configurada.';
            eliminar.disabled = !firmaDisponible;
            guardar.disabled = !(archivo.files && archivo.files.length > 0);
        };

        const mostrarMensaje = function (mensaje, error) {
            estado.textContent = String(mensaje || '');
            estado.classList.toggle('text-danger', Boolean(error));
            estado.classList.toggle('text-success', !error && Boolean(mensaje));
        };

        const cargar = async function () {
            const url = String(modal.dataset.signatureStatusUrl || '');
            if (!url) {
                return;
            }

            archivo.value = '';
            guardar.disabled = true;
            mostrarMensaje('Consultando firma...', false);

            try {
                const respuesta = await fetch(url, {
                    headers: { 'X-Requested-With': 'fetch' },
                    cache: 'no-store'
                });
                const datos = await respuesta.json();
                if (!respuesta.ok || !datos.ok) {
                    throw new Error(datos.mensaje || 'No fue posible consultar la firma.');
                }
                estado.classList.remove('text-danger', 'text-success');
                actualizarEstado(datos.firma || {});
            } catch (error) {
                mostrarMensaje(error.message, true);
            }
        };

        archivo.addEventListener('change', function () {
            const seleccionado = archivo.files && archivo.files[0];
            guardar.disabled = !seleccionado;

            if (!seleccionado) {
                cargar();
                return;
            }

            const lector = new FileReader();
            lector.addEventListener('load', function () {
                mostrarPreview(String(lector.result || ''));
                estado.classList.remove('text-danger', 'text-success');
                estado.textContent = 'Vista previa. Pulsa “Guardar firma” para aplicarla.';
            });
            lector.readAsDataURL(seleccionado);
        });

        guardar.addEventListener('click', async function () {
            const seleccionado = archivo.files && archivo.files[0];
            const url = String(modal.dataset.signatureSaveUrl || '');
            if (!seleccionado || !url) {
                return;
            }

            const datos = new FormData();
            datos.append('firma_correo', seleccionado);
            guardar.disabled = true;
            eliminar.disabled = true;
            mostrarMensaje('Guardando firma...', false);

            try {
                const respuesta = await fetch(url, {
                    method: 'POST',
                    headers: { 'X-Requested-With': 'fetch' },
                    body: datos
                });
                const json = await respuesta.json();
                if (!respuesta.ok || !json.ok) {
                    throw new Error(json.mensaje || 'No fue posible guardar la firma.');
                }

                archivo.value = '';
                estado.classList.remove('text-danger', 'text-success');
                actualizarEstado(json.firma || { disponible: true });
                mostrarMensaje(json.mensaje || 'Firma actualizada correctamente.', false);
                eliminar.disabled = false;
            } catch (error) {
                mostrarMensaje(error.message, true);
                guardar.disabled = false;
                eliminar.disabled = !firmaDisponible;
            }
        });

        const limpiarErrorConfirmacion = function () {
            if (!errorConfirmacion) {
                return;
            }

            errorConfirmacion.textContent = '';
            errorConfirmacion.classList.add('d-none');
        };

        const mostrarErrorConfirmacion = function (mensaje) {
            if (!errorConfirmacion) {
                return;
            }

            errorConfirmacion.textContent = String(
                mensaje || 'No fue posible quitar la firma.'
            );
            errorConfirmacion.classList.remove('d-none');
        };

        eliminar.addEventListener('click', function () {
            const url = String(modal.dataset.signatureDeleteUrl || '');
            if (!url || !firmaDisponible || eliminandoFirma) {
                return;
            }

            limpiarErrorConfirmacion();
            reabrirPerfil = true;

            const abrirConfirmacion = function () {
                modalConfirmacion.show();
            };

            if (modal.classList.contains('show')) {
                modal.addEventListener(
                    'hidden.bs.modal',
                    abrirConfirmacion,
                    { once: true }
                );
                modalPerfil.hide();
                return;
            }

            abrirConfirmacion();
        });

        confirmarEliminar.addEventListener('click', async function () {
            const url = String(modal.dataset.signatureDeleteUrl || '');
            if (!url || !firmaDisponible || eliminandoFirma) {
                return;
            }

            eliminandoFirma = true;
            limpiarErrorConfirmacion();
            confirmarEliminar.disabled = true;

            const textoOriginal = confirmarEliminar.innerHTML;
            confirmarEliminar.innerHTML =
                '<span class="spinner-border spinner-border-sm" aria-hidden="true"></span>' +
                '<span>Quitando...</span>';

            try {
                const respuesta = await fetch(url, {
                    method: 'POST',
                    headers: { 'X-Requested-With': 'fetch' }
                });
                const json = await respuesta.json();

                if (!respuesta.ok || !json.ok) {
                    throw new Error(
                        json.mensaje ||
                        'No fue posible eliminar la firma.'
                    );
                }

                archivo.value = '';
                firmaDisponible = false;
                mostrarPreview('');
                mostrarMensaje(
                    json.mensaje || 'Firma eliminada.',
                    false
                );
                eliminar.disabled = true;
                guardar.disabled = true;
                modalConfirmacion.hide();
            } catch (error) {
                mostrarErrorConfirmacion(error.message);
            } finally {
                eliminandoFirma = false;
                confirmarEliminar.disabled = false;
                confirmarEliminar.innerHTML = textoOriginal;
            }
        });

        confirmacion.addEventListener('hidden.bs.modal', function () {
            limpiarErrorConfirmacion();

            if (!reabrirPerfil) {
                return;
            }

            reabrirPerfil = false;
            modalPerfil.show();
        });

        modal.addEventListener('shown.bs.modal', cargar);
    });
})();
