(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        const modal = document.querySelector('[data-my-profile-modal]');
        if (!modal) {
            return;
        }

        const urlEstado = modal.dataset.mailAccountStatusUrl || '';
        const urlGuardar = modal.dataset.mailAccountSaveUrl || '';
        const urlEliminar = modal.dataset.mailAccountDeleteUrl || '';

        const proveedor = modal.querySelector('[data-mail-account-provider]');
        const correo = modal.querySelector('[data-mail-account-email]');
        const host = modal.querySelector('[data-mail-account-host]');
        const puerto = modal.querySelector('[data-mail-account-port]');
        const cifrado = modal.querySelector('[data-mail-account-encryption]');
        const usuario = modal.querySelector('[data-mail-account-username]');
        const password = modal.querySelector('[data-mail-account-password]');
        const estado = modal.querySelector('[data-mail-account-status]');
        const guardar = modal.querySelector('[data-mail-account-save]');
        const eliminar = modal.querySelector('[data-mail-account-delete]');

        if (
            !proveedor || !correo || !host || !puerto || !cifrado ||
            !usuario || !password || !estado || !guardar || !eliminar
        ) {
            return;
        }

        const presets = {
            GMAIL: {
                host: 'smtp.gmail.com',
                port: '587',
                encryption: 'tls'
            },
            MICROSOFT: {
                host: 'smtp.office365.com',
                port: '587',
                encryption: 'tls'
            },
            HOSTINGER: {
                host: 'smtp.hostinger.com',
                port: '587',
                encryption: 'tls'
            }
        };

        const toast = function (mensaje, error) {
            let contenedor = document.querySelector('.toast-container');

            if (!contenedor) {
                contenedor = document.createElement('div');
                contenedor.className =
                    'toast-container position-fixed top-0 end-0 p-3';
                contenedor.style.zIndex = '1100';
                document.body.appendChild(contenedor);
            }

            const elemento = document.createElement('div');
            elemento.className =
                'toast system-toast' + (error ? ' system-toast-error' : '');
            elemento.setAttribute('role', error ? 'alert' : 'status');
            elemento.innerHTML =
                '<div class="toast-body">' +
                    '<i class="bi ' +
                    (error ? 'bi-exclamation-circle' : 'bi-check2-circle') +
                    '"></i>' +
                    '<span></span>' +
                '</div>';

            elemento.querySelector('span').textContent = mensaje;
            contenedor.appendChild(elemento);

            elemento.addEventListener('hidden.bs.toast', function () {
                elemento.remove();
            });

            bootstrap.Toast.getOrCreateInstance(elemento, {
                autohide: true,
                delay: error ? 4500 : 3000
            }).show();
        };

        const aplicarPreset = function (forzar) {
            const preset = presets[proveedor.value];

            if (!preset) {
                host.readOnly = false;
                return;
            }

            host.readOnly = false;

            if (forzar || host.value.trim() === '') {
                host.value = preset.host;
            }

            if (forzar || puerto.value.trim() === '') {
                puerto.value = preset.port;
            }

            if (forzar || cifrado.value === '') {
                cifrado.value = preset.encryption;
            }
        };

        const sincronizarUsuario = function () {
            if (usuario.value.trim() === '') {
                usuario.value = correo.value.trim();
            }
        };

        const pintarEstado = function (configurada, cuenta) {
            estado.textContent = configurada
                ? 'Cuenta conectada'
                : 'Sin configurar';

            estado.classList.toggle('text-bg-success', configurada);
            estado.classList.toggle('text-bg-light', !configurada);
            eliminar.disabled = !configurada;

            if (!configurada || !cuenta) {
                return;
            }

            proveedor.value = cuenta.proveedor || 'OTRO';
            correo.value = cuenta.correo || '';
            host.value = cuenta.smtp_host || '';
            puerto.value = String(cuenta.smtp_port || 587);
            cifrado.value = cuenta.smtp_encryption || 'tls';
            usuario.value = cuenta.smtp_username || cuenta.correo || '';
            password.value = '';
        };

        const cargar = async function () {
            if (!urlEstado) {
                return;
            }

            try {
                const respuesta = await fetch(urlEstado, {
                    headers: {
                        'X-Requested-With': 'fetch'
                    }
                });
                const json = await respuesta.json();

                if (!respuesta.ok || !json.ok) {
                    throw new Error(
                        json.mensaje || 'No fue posible consultar la cuenta de correo.'
                    );
                }

                pintarEstado(Boolean(json.configurada), json.cuenta || null);

                if (!json.configurada) {
                    aplicarPreset(true);
                }
            } catch (error) {
                pintarEstado(false, null);
                console.error(error);
            }
        };

        proveedor.addEventListener('change', function () {
            aplicarPreset(true);
        });

        correo.addEventListener('change', sincronizarUsuario);

        guardar.addEventListener('click', async function () {
            sincronizarUsuario();

            const datos = new FormData();
            datos.set('proveedor', proveedor.value);
            datos.set('correo', correo.value.trim());
            datos.set('smtp_host', host.value.trim());
            datos.set('smtp_port', puerto.value.trim());
            datos.set('smtp_encryption', cifrado.value);
            datos.set('smtp_username', usuario.value.trim());
            datos.set('smtp_password', password.value);

            const html = guardar.innerHTML;
            guardar.disabled = true;
            guardar.innerHTML =
                '<span class="spinner-border spinner-border-sm me-2"></span>' +
                'Guardando...';

            try {
                const respuesta = await fetch(urlGuardar, {
                    method: 'POST',
                    body: datos,
                    headers: {
                        'X-Requested-With': 'fetch'
                    }
                });
                const json = await respuesta.json();

                if (!respuesta.ok || !json.ok) {
                    throw new Error(
                        json.mensaje || 'No fue posible guardar la cuenta.'
                    );
                }

                password.value = '';
                toast(json.mensaje || 'Cuenta de correo guardada.', false);
                await cargar();
            } catch (error) {
                toast(error.message, true);
            } finally {
                guardar.disabled = false;
                guardar.innerHTML = html;
            }
        });

        eliminar.addEventListener('click', async function () {
            if (!urlEliminar || eliminar.disabled) {
                return;
            }

            if (!window.confirm('¿Quieres quitar la cuenta de correo conectada?')) {
                return;
            }

            eliminar.disabled = true;

            try {
                const respuesta = await fetch(urlEliminar, {
                    method: 'POST',
                    headers: {
                        'X-Requested-With': 'fetch'
                    }
                });
                const json = await respuesta.json();

                if (!respuesta.ok || !json.ok) {
                    throw new Error(
                        json.mensaje || 'No fue posible quitar la cuenta.'
                    );
                }

                correo.value = '';
                usuario.value = '';
                password.value = '';
                proveedor.value = 'GMAIL';
                aplicarPreset(true);
                pintarEstado(false, null);
                toast(json.mensaje || 'Cuenta de correo eliminada.', false);
            } catch (error) {
                toast(error.message, true);
                eliminar.disabled = false;
            }
        });

        modal.addEventListener('show.bs.modal', cargar);
        cargar();
    });
})();
