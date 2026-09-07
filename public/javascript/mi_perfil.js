(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        const botonAbrir = document.querySelector('[data-my-profile-open]');
        const modalElemento = document.querySelector('[data-my-profile-modal]');
        const formulario = document.querySelector('[data-my-profile-form]');

        if (!botonAbrir || !modalElemento || !formulario || !window.bootstrap) {
            return;
        }

        const modal = bootstrap.Modal.getOrCreateInstance(modalElemento);
        const alerta = modalElemento.querySelector('[data-my-profile-alert]');
        const vistaPrevia = modalElemento.querySelector('[data-my-profile-preview]');
        const fotoInput = modalElemento.querySelector('[data-my-profile-photo]');
        const botonGuardar = modalElemento.querySelector('[data-my-profile-submit]');
        const modalFotoElemento = document.getElementById('modalVistaFotoPerfil');
        let perfilActual = null;

        const mostrarAlerta = function (mensaje, tipo) {
            if (!alerta) {
                return;
            }

            alerta.textContent = mensaje || '';
            alerta.classList.toggle('d-none', !mensaje);
            alerta.classList.toggle('alert-success', tipo === 'success');
            alerta.classList.toggle('alert-danger', tipo === 'error');
        };

        const mostrarToastSistema = function (mensaje) {
            let contenedor = document.querySelector('.toast-container');

            if (!contenedor) {
                contenedor = document.createElement('div');
                contenedor.className = 'toast-container position-fixed top-0 end-0 p-3';
                document.body.appendChild(contenedor);
            }

            const toast = document.createElement('div');
            toast.className = 'toast system-toast';
            toast.setAttribute('role', 'status');
            toast.setAttribute('aria-live', 'polite');
            toast.setAttribute('aria-atomic', 'true');
            toast.setAttribute('data-bs-delay', '3200');
            toast.innerHTML =
                '<div class="toast-body">' +
                    '<i class="bi bi-check2-circle"></i>' +
                    '<span></span>' +
                '</div>';
            toast.querySelector('span').textContent = mensaje;
            contenedor.appendChild(toast);

            const instanciaToast = new bootstrap.Toast(toast);
            toast.addEventListener('hidden.bs.toast', function () {
                toast.remove();
            });
            instanciaToast.show();
        };

        const mostrarFoto = function (url, iniciales) {
            if (!vistaPrevia) {
                return;
            }

            vistaPrevia.replaceChildren();

            if (url) {
                const imagen = document.createElement('img');
                imagen.src = url;
                imagen.alt = 'Foto de perfil';
                vistaPrevia.appendChild(imagen);
                return;
            }

            const texto = document.createElement('strong');
            texto.textContent = iniciales || 'US';
            vistaPrevia.appendChild(texto);
        };

        const configurarVisorFoto = function (url, perfil) {
            if (!vistaPrevia) {
                return;
            }

            const nombreCompleto = [perfil?.nombre, perfil?.apellidos].filter(Boolean).join(' ').trim();
            vistaPrevia.setAttribute('data-photo-url', url || '');
            vistaPrevia.setAttribute('data-photo-name', nombreCompleto || 'Usuario');
            vistaPrevia.setAttribute('data-photo-role', perfil?.rol || 'Usuario');
            vistaPrevia.setAttribute('aria-label', 'Ver foto de ' + (nombreCompleto || 'Usuario'));
            vistaPrevia.style.cursor = url ? 'pointer' : '';
        };

        const formatearFecha = function (fecha) {
            if (!fecha) {
                return 'Sin registro';
            }

            const valor = new Date(String(fecha).replace(' ', 'T'));
            return Number.isNaN(valor.getTime())
                ? fecha
                : valor.toLocaleString('es-MX', { dateStyle: 'medium', timeStyle: 'short' });
        };

        const cargarFormulario = function (perfil) {
            perfilActual = perfil;
            formulario.elements.nombre.value = perfil.nombre || '';
            formulario.elements.apellidos.value = perfil.apellidos || '';
            formulario.elements.telefono.value = perfil.telefono || '';
            formulario.elements.correo.value = perfil.correo || '';
            modalElemento.querySelector('[data-my-profile-username]').value = perfil.usuario || '';
            modalElemento.querySelector('[data-my-profile-role]').value = perfil.rol || '';
            modalElemento.querySelector('[data-my-profile-status]').value = perfil.estado || '';
            modalElemento.querySelector('[data-my-profile-last-access]').value = formatearFecha(perfil.ultimo_acceso);
            mostrarFoto(perfil.foto_url || '', perfil.iniciales || 'US');
            configurarVisorFoto(perfil.foto_url || '', perfil);
        };

        const actualizarHeader = function (perfil) {
            const nombreCompleto = [perfil.nombre, perfil.apellidos].filter(Boolean).join(' ').trim();
            const nombreHeader = document.querySelector('.topbar-account-name');
            const avatar = document.querySelector('.topbar-user-avatar');

            if (nombreHeader) {
                nombreHeader.textContent = nombreCompleto || 'Usuario';
            }

            if (!avatar) {
                return;
            }

            avatar.replaceChildren();
            avatar.classList.toggle('system-avatar-initials', !perfil.foto_url);

            if (perfil.foto_url) {
                const imagen = document.createElement('img');
                imagen.className = 'system-avatar-image';
                imagen.src = perfil.foto_url;
                imagen.alt = 'Foto de ' + (nombreCompleto || 'Usuario');
                imagen.dataset.avatarInitials = perfil.iniciales || 'US';
                avatar.appendChild(imagen);
            } else {
                avatar.textContent = perfil.iniciales || 'US';
            }
        };

        botonAbrir.addEventListener('click', async function (event) {
            event.preventDefault();
            formulario.reset();
            mostrarAlerta('', '');
            botonGuardar.disabled = true;
            modal.show();

            try {
                const respuesta = await fetch(modalElemento.dataset.profileLoadUrl, {
                    headers: { 'X-Requested-With': 'fetch' }
                });
                const datos = await respuesta.json();

                if (!respuesta.ok || !datos.ok) {
                    throw new Error(datos.mensaje || 'No fue posible cargar tu perfil.');
                }

                cargarFormulario(datos.perfil);
                botonGuardar.disabled = false;
            } catch (error) {
                mostrarAlerta(error.message, 'error');
            }
        });

        fotoInput?.addEventListener('change', function () {
            const archivo = fotoInput.files && fotoInput.files[0];

            if (!archivo) {
                mostrarFoto(perfilActual?.foto_url || '', perfilActual?.iniciales || 'US');
                configurarVisorFoto(perfilActual?.foto_url || '', perfilActual);
                return;
            }

            const lector = new FileReader();
            lector.addEventListener('load', function () {
                const fotoTemporal = String(lector.result || '');
                mostrarFoto(fotoTemporal, perfilActual?.iniciales || 'US');
                configurarVisorFoto(fotoTemporal, perfilActual);
            });
            lector.readAsDataURL(archivo);
        });

        modalFotoElemento?.addEventListener('hidden.bs.modal', function () {
            if (modalElemento.classList.contains('show')) {
                document.body.classList.add('modal-open');
                modalElemento.focus();
            }
        });

        formulario.addEventListener('submit', async function (event) {
            event.preventDefault();
            mostrarAlerta('', '');

            if (!formulario.checkValidity()) {
                formulario.reportValidity();
                return;
            }

            const textoOriginal = botonGuardar.textContent;
            botonGuardar.disabled = true;
            botonGuardar.textContent = 'Actualizando...';

            try {
                const respuesta = await fetch(modalElemento.dataset.profileUpdateUrl, {
                    method: 'POST',
                    headers: { 'X-Requested-With': 'fetch' },
                    body: new FormData(formulario)
                });
                const datos = await respuesta.json();

                if (!respuesta.ok || !datos.ok) {
                    throw new Error(datos.mensaje || 'No fue posible actualizar el perfil.');
                }

                cargarFormulario(datos.perfil);
                actualizarHeader(datos.perfil);
                formulario.elements.foto_perfil.value = '';
                mostrarToastSistema(datos.mensaje || 'Perfil actualizado correctamente.');
            } catch (error) {
                mostrarAlerta(error.message, 'error');
            } finally {
                botonGuardar.disabled = false;
                botonGuardar.textContent = textoOriginal;
            }
        });
    });
})();
