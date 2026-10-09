document.addEventListener('DOMContentLoaded', function () {
    'use strict';

    const form = document.querySelector(
        '[data-formulario-registro-publico]'
    );

    if (!form) {
        return;
    }

    const nombre = form.querySelector('[data-publico-nombre]');
    const apellido = form.querySelector('[data-publico-apellido]');
    const fecha = form.querySelector('[data-publico-fecha]');
    const movil = form.querySelector('[data-publico-movil]');
    const movilConfirmacion = form.querySelector(
        '[data-publico-movil-confirmacion]'
    );
    const movilSecundario = form.querySelector(
        '[data-publico-movil-secundario]'
    );
    const correo = form.querySelector('[data-publico-correo]');
    const correoConfirmacion = form.querySelector(
        '[data-publico-correo-confirmacion]'
    );
    const perfil = form.querySelector('[data-publico-perfil]');
    const lugar = form.querySelector('[data-publico-lugar]');
    const cargo = form.querySelector('[data-publico-cargo]');
    const estado = form.querySelector('[data-publico-estado]');
    const municipio = form.querySelector('[data-publico-municipio]');
    const submit = form.querySelector('[data-publico-submit]');

    let guardando = false;
    let cargandoMunicipios = false;

    const mostrarToast = function (mensaje, esError) {
        let contenedor = document.querySelector('.toast-container');

        if (!contenedor) {
            contenedor = document.createElement('div');
            contenedor.className =
                'toast-container position-fixed top-0 end-0 p-3';
            document.body.appendChild(contenedor);
        }

        const toast = document.createElement('div');
        toast.className =
            'toast system-toast' +
            (esError ? ' system-toast-error' : '');
        toast.setAttribute('role', esError ? 'alert' : 'status');
        toast.innerHTML =
            '<div class="toast-body">' +
                '<i class="bi ' +
                    (esError
                        ? 'bi-exclamation-circle'
                        : 'bi-check2-circle') +
                '"></i>' +
                '<span></span>' +
            '</div>';

        toast.querySelector('span').textContent = String(
            mensaje || ''
        );

        contenedor.appendChild(toast);

        toast.addEventListener('hidden.bs.toast', function () {
            toast.remove();
        });

        bootstrap.Toast.getOrCreateInstance(toast, {
            autohide: true,
            delay: esError ? 4500 : 3200
        }).show();
    };

    const marcar = function (campo, valido) {
        if (!campo) {
            return valido;
        }

        campo.classList.toggle('is-invalid', !valido);
        campo.classList.toggle(
            'is-valid',
            valido && String(campo.value || '').trim() !== ''
        );

        return valido;
    };

    const validarNombre = function (campo) {
        const valor = String(campo?.value || '').trim();
        const patron = /^[\p{L}\p{M}][\p{L}\p{M} .'’-]*$/u;

        return marcar(
            campo,
            valor.length >= 2 && patron.test(valor)
        );
    };

    const validarFecha = function () {
        const valor = String(fecha?.value || '');

        if (valor === '') {
            return marcar(fecha, false);
        }

        const seleccionada = new Date(valor + 'T00:00:00');
        const hoy = new Date();
        hoy.setHours(0, 0, 0, 0);

        return marcar(
            fecha,
            !Number.isNaN(seleccionada.getTime()) &&
            seleccionada <= hoy &&
            seleccionada >= new Date('1900-01-01T00:00:00')
        );
    };

    const normalizarTelefono = function (campo) {
        const limpio = String(campo?.value || '')
            .replace(/\D+/g, '')
            .slice(0, 10);

        if (campo) {
            campo.value = limpio;
        }

        return limpio;
    };

    const validarMoviles = function () {
        const principal = normalizarTelefono(movil);
        const confirmacion = normalizarTelefono(movilConfirmacion);
        const secundario = normalizarTelefono(movilSecundario);

        const principalValido = marcar(
            movil,
            /^\d{10}$/.test(principal)
        );

        const confirmacionValida = marcar(
            movilConfirmacion,
            /^\d{10}$/.test(confirmacion) &&
            principal === confirmacion
        );

        const secundarioValido = marcar(
            movilSecundario,
            secundario === '' ||
            (
                /^\d{10}$/.test(secundario) &&
                secundario !== principal
            )
        );

        return principalValido &&
            confirmacionValida &&
            secundarioValido;
    };

    const validarCorreos = function () {
        const principal = String(correo?.value || '')
            .trim()
            .toLowerCase();
        const confirmacion = String(
            correoConfirmacion?.value || ''
        )
            .trim()
            .toLowerCase();
        const formatoValido = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

        const principalValido = marcar(
            correo,
            formatoValido.test(principal)
        );

        const confirmacionValida = marcar(
            correoConfirmacion,
            formatoValido.test(confirmacion) &&
            principal === confirmacion
        );

        return principalValido && confirmacionValida;
    };

    const validarSelect = function (campo) {
        return marcar(
            campo,
            String(campo?.value || '').trim() !== ''
        );
    };

    const validarFormulario = function () {
        return [
            validarNombre(nombre),
            validarNombre(apellido),
            validarFecha(),
            validarMoviles(),
            validarCorreos(),
            validarSelect(perfil),
            validarSelect(estado),
            validarSelect(municipio)
        ].every(Boolean);
    };

    const escapar = function (valor) {
        const div = document.createElement('div');
        div.textContent = String(valor == null ? '' : valor);
        return div.innerHTML;
    };

    const limpiarMunicipios = function () {
        if (!municipio) {
            return;
        }

        municipio.innerHTML =
            '<option value="">Primero selecciona un estado</option>';
        municipio.disabled = true;
        municipio.classList.remove('is-valid', 'is-invalid');
    };

    const cargarMunicipios = async function () {
        const estadoId = Number(estado?.value || 0);

        if (!municipio || estadoId <= 0) {
            limpiarMunicipios();
            return;
        }

        const urlBase = String(
            estado.getAttribute('data-municipios-url') || ''
        );

        cargandoMunicipios = true;
        municipio.disabled = true;
        municipio.innerHTML =
            '<option value="">Cargando municipios...</option>';

        try {
            const separador = urlBase.includes('?') ? '&' : '?';
            const respuesta = await fetch(
                urlBase +
                separador +
                'estado_id=' +
                encodeURIComponent(estadoId),
                {
                    headers: {
                        'X-Requested-With': 'fetch'
                    }
                }
            );

            const json = await respuesta.json();

            if (!respuesta.ok || !json.ok) {
                throw new Error(
                    json.mensaje ||
                    'No fue posible cargar los municipios.'
                );
            }

            const municipios = Array.isArray(json.municipios)
                ? json.municipios
                : [];

            municipio.innerHTML =
                '<option value="">Selecciona un municipio</option>' +
                municipios.map(function (item) {
                    return (
                        '<option value="' +
                            Number(item.id || 0) +
                        '">' +
                            escapar(item.nombre || '') +
                        '</option>'
                    );
                }).join('');

            municipio.disabled = municipios.length === 0;
        } catch (error) {
            console.error(error);
            limpiarMunicipios();
            mostrarToast(
                error.message ||
                'No fue posible cargar los municipios.',
                true
            );
        } finally {
            cargandoMunicipios = false;
        }
    };

    nombre?.addEventListener('blur', function () {
        validarNombre(nombre);
    });

    apellido?.addEventListener('blur', function () {
        validarNombre(apellido);
    });

    fecha?.addEventListener('change', validarFecha);

    [movil, movilConfirmacion, movilSecundario].forEach(
        function (campo) {
            campo?.addEventListener('input', function () {
                normalizarTelefono(campo);

                if (
                    String(movil?.value || '').length === 10 ||
                    String(movilConfirmacion?.value || '').length === 10 ||
                    String(movilSecundario?.value || '').length === 10
                ) {
                    validarMoviles();
                }
            });

            campo?.addEventListener('blur', validarMoviles);
        }
    );

    [correo, correoConfirmacion].forEach(function (campo) {
        campo?.addEventListener('blur', validarCorreos);
        campo?.addEventListener('input', function () {
            if (
                String(correo?.value || '').trim() !== '' &&
                String(correoConfirmacion?.value || '').trim() !== ''
            ) {
                validarCorreos();
            }
        });
    });

    perfil?.addEventListener('change', function () {
        validarSelect(perfil);
    });

    estado?.addEventListener('change', function () {
        validarSelect(estado);
        cargarMunicipios();
    });

    municipio?.addEventListener('change', function () {
        validarSelect(municipio);
    });

    form.addEventListener('submit', async function (event) {
        event.preventDefault();

        if (guardando || cargandoMunicipios) {
            return;
        }

        if (!validarFormulario()) {
            form.querySelector('.is-invalid')?.focus();
            mostrarToast(
                'Completa correctamente los campos obligatorios.',
                true
            );
            return;
        }

        const data = new FormData(form);

        /*
         * Lugar de trabajo y cargo son opcionales.
         * Perfil de interés continúa siendo obligatorio.
         */
        data.set(
            'movil',
            normalizarTelefono(movil)
        );
        data.set(
            'movil_confirmacion',
            normalizarTelefono(movilConfirmacion)
        );
        data.set(
            'movil_secundario',
            normalizarTelefono(movilSecundario)
        );
        data.set(
            'correo',
            String(correo?.value || '').trim().toLowerCase()
        );
        data.set(
            'correo_confirmacion',
            String(correoConfirmacion?.value || '')
                .trim()
                .toLowerCase()
        );
        data.set(
            'perfil_interes',
            String(perfil?.value || '').trim()
        );
        data.set(
            'lugar_laboras',
            String(lugar?.value || '').trim()
        );
        data.set(
            'cargo_puesto',
            String(cargo?.value || '').trim()
        );

        guardando = true;
        const contenidoOriginal = submit?.innerHTML || '';

        if (submit) {
            submit.disabled = true;
            submit.innerHTML =
                '<span class="spinner-border spinner-border-sm" ' +
                'aria-hidden="true"></span>' +
                '<span>Enviando...</span>';
        }

        try {
            const respuesta = await fetch(form.action, {
                method: 'POST',
                body: data,
                headers: {
                    'X-Requested-With': 'fetch'
                }
            });

            const json = await respuesta.json();

            if (!respuesta.ok || !json.ok) {
                throw new Error(
                    json.mensaje ||
                    'No fue posible enviar el formulario.'
                );
            }

            const redirectUrl = String(
                json.redirect_url || ''
            ).trim();

            if (redirectUrl !== '') {
                window.location.assign(redirectUrl);
                return;
            }

            mostrarToast(
                json.mensaje ||
                'Tu registro fue enviado correctamente.',
                false
            );
        } catch (error) {
            console.error(error);
            mostrarToast(
                error.message ||
                'No fue posible enviar el formulario.',
                true
            );
        } finally {
            guardando = false;

            if (submit) {
                submit.disabled = false;
                submit.innerHTML = contenidoOriginal;
            }
        }
    });
});
