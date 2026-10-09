document.addEventListener('DOMContentLoaded', function () {
    'use strict';

    const form = document.querySelector('[data-formulario-registro]');

    if (!form) {
        return;
    }

    const estado = form.querySelector('[data-registro-estado]');
    const municipio = form.querySelector('[data-registro-municipio]');
    const ayudaMunicipio = form.querySelector(
        '[data-registro-municipio-ayuda]'
    );
    const nombre = form.querySelector('[data-registro-nombre]');
    const apellido = form.querySelector('[data-registro-apellido]');
    const fecha = form.querySelector('[data-registro-fecha]');
    const perfil = form.querySelector('[data-registro-perfil]');
    const movil = form.querySelector('[data-registro-movil]');
    const movilConfirmacion = form.querySelector(
        '[data-registro-movil-confirmacion]'
    );
    const correo = form.querySelector('[data-registro-correo]');
    const correoConfirmacion = form.querySelector(
        '[data-registro-correo-confirmacion]'
    );
    const lugarLaboras = form.querySelector(
        '[data-registro-lugar-laboras]'
    );
    const botonSubmit = form.querySelector('[data-registro-submit]');
    const compartir = document.querySelector('[data-formulario-share]');
    const botonGenerarLink = compartir?.querySelector(
        '[data-generate-form-link]'
    );
    const botonGenerarQr = compartir?.querySelector(
        '[data-generate-form-qr]'
    );
    const resultadoLink = compartir?.querySelector(
        '[data-form-link-result]'
    );
    const inputLink = compartir?.querySelector(
        '[data-form-link-input]'
    );
    const botonCopiarLink = compartir?.querySelector(
        '[data-copy-form-link]'
    );
    const notaLink = compartir?.querySelector(
        '[data-form-link-note]'
    );
    const modalQrElement = document.getElementById(
        'modalFormularioRegistroQr'
    );
    const imagenQr = modalQrElement?.querySelector(
        '[data-form-qr-image]'
    );
    const loaderQr = modalQrElement?.querySelector(
        '[data-form-qr-loader]'
    );
    const enlaceQr = modalQrElement?.querySelector(
        '[data-form-qr-link]'
    );

    let cargandoMunicipios = false;
    let guardando = false;
    let generandoLink = false;
    let enlacePublico = String(
        compartir?.getAttribute('data-current-link') || ''
    ).trim();

    const mostrarToast = function (mensaje, esError) {
        let contenedor = document.querySelector('.toast-container');

        if (!contenedor) {
            contenedor = document.createElement('div');
            contenedor.className =
                'toast-container position-fixed top-0 end-0 p-3';
            contenedor.style.zIndex = '1095';
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

        toast.querySelector('span').textContent = String(mensaje || '');
        contenedor.appendChild(toast);

        toast.addEventListener('hidden.bs.toast', function () {
            toast.remove();
        });

        bootstrap.Toast.getOrCreateInstance(toast, {
            autohide: true,
            delay: esError ? 4500 : 3200
        }).show();
    };

    const actualizarEnlaceCompartido = function (url) {
        enlacePublico = String(url || '').trim();

        if (compartir) {
            compartir.setAttribute('data-current-link', enlacePublico);
        }

        if (inputLink) {
            inputLink.value = enlacePublico;
        }

        resultadoLink?.classList.toggle(
            'd-none',
            enlacePublico === ''
        );

        if (botonGenerarQr) {
            botonGenerarQr.disabled = enlacePublico === '';
        }

        if (botonGenerarLink) {
            botonGenerarLink.innerHTML =
                '<i class="bi bi-link-45deg"></i>' +
                (enlacePublico === '' ? 'Generar link' : 'Ver link');
        }

        if (notaLink) {
            try {
                const url = new URL(enlacePublico);

                if (
                    url.hostname === 'localhost' ||
                    url.hostname === '127.0.0.1'
                ) {
                    notaLink.textContent =
                        'Este enlace funciona en tu entorno local. Para abrirlo desde otro dispositivo, publica el sistema en un dominio o túnel accesible.';
                } else {
                    notaLink.textContent =
                        'El enlace está listo para compartirse con los usuarios.';
                }
            } catch (error) {
                notaLink.textContent =
                    'El enlace apunta al formulario público de Registro.';
            }
        }
    };

    const generarEnlacePublico = async function () {
        if (!compartir || !botonGenerarLink || generandoLink) {
            return;
        }

        if (enlacePublico !== '') {
            resultadoLink?.classList.remove('d-none');
            inputLink?.focus();
            inputLink?.select();
            return;
        }

        const url = String(
            compartir.getAttribute('data-generate-link-url') || ''
        ).trim();

        if (url === '') {
            mostrarToast(
                'No se encontró la ruta para generar el enlace.',
                true
            );
            return;
        }

        const htmlOriginal = botonGenerarLink.innerHTML;
        generandoLink = true;
        botonGenerarLink.disabled = true;
        botonGenerarLink.innerHTML =
            '<span class="spinner-border spinner-border-sm" ' +
            'aria-hidden="true"></span> Generando...';

        try {
            const respuesta = await fetch(url, {
                method: 'POST',
                headers: {
                    'X-Requested-With': 'fetch'
                }
            });

            const json = await respuesta.json();

            if (!respuesta.ok || !json.ok) {
                throw new Error(
                    json.mensaje ||
                    'No fue posible generar el enlace.'
                );
            }

            actualizarEnlaceCompartido(json.url || '');

            mostrarToast(
                json.mensaje ||
                'Enlace generado correctamente.',
                false
            );
        } catch (error) {
            console.error(error);
            mostrarToast(
                error.message ||
                'No fue posible generar el enlace.',
                true
            );
        } finally {
            generandoLink = false;
            botonGenerarLink.disabled = false;

            if (enlacePublico === '') {
                botonGenerarLink.innerHTML = htmlOriginal;
            }
        }
    };

    const copiarEnlacePublico = async function () {
        if (enlacePublico === '') {
            return;
        }

        try {
            await navigator.clipboard.writeText(enlacePublico);
            mostrarToast('Enlace copiado correctamente.', false);
        } catch (error) {
            if (inputLink) {
                inputLink.focus();
                inputLink.select();
                document.execCommand('copy');
                mostrarToast('Enlace copiado correctamente.', false);
            }
        }
    };

    const generarQr = function () {
        if (
            enlacePublico === '' ||
            !compartir ||
            !modalQrElement ||
            !imagenQr
        ) {
            mostrarToast(
                'Primero genera el enlace del formulario.',
                true
            );
            return;
        }

        const quickChartBase = String(
            compartir.getAttribute('data-quickchart-url') ||
            'https://quickchart.io/qr'
        ).trim();

        const parametros = new URLSearchParams({
            text: enlacePublico,
            size: '300',
            margin: '2',
            dark: '223A84',
            light: 'ffffff',
            ecLevel: 'M',
            format: 'png'
        });

        const qrUrl = quickChartBase + '?' + parametros.toString();

        if (loaderQr) {
            loaderQr.classList.remove('d-none');
        }

        imagenQr.classList.add('is-loading');
        imagenQr.src = qrUrl;

        if (enlaceQr) {
            enlaceQr.textContent = enlacePublico;
        }

        bootstrap.Modal.getOrCreateInstance(
            modalQrElement
        ).show();
    };

    botonGenerarLink?.addEventListener(
        'click',
        generarEnlacePublico
    );
    botonCopiarLink?.addEventListener(
        'click',
        copiarEnlacePublico
    );
    botonGenerarQr?.addEventListener(
        'click',
        generarQr
    );

    imagenQr?.addEventListener('load', function () {
        loaderQr?.classList.add('d-none');
        imagenQr.classList.remove('is-loading');
    });

    imagenQr?.addEventListener('error', function () {
        loaderQr?.classList.add('d-none');
        imagenQr.classList.remove('is-loading');
        mostrarToast(
            'No fue posible generar el código QR en este momento.',
            true
        );
    });

    if (enlacePublico !== '') {
        actualizarEnlaceCompartido(enlacePublico);
    }

    const escapar = function (valor) {
        const div = document.createElement('div');
        div.textContent = String(valor == null ? '' : valor);
        return div.innerHTML;
    };

    const normalizarTelefono = function (campo) {
        if (!campo) {
            return '';
        }

        const limpio = String(campo.value || '')
            .replace(/\D+/g, '')
            .slice(0, 10);

        if (campo.value !== limpio) {
            campo.value = limpio;
        }

        return limpio;
    };

    const marcarCampo = function (campo, valido, mensaje) {
        if (!campo) {
            return valido;
        }

        campo.setCustomValidity(valido ? '' : String(mensaje || 'Dato inválido.'));
        campo.classList.toggle('is-invalid', !valido);
        campo.classList.toggle(
            'is-valid',
            valido && String(campo.value || '').trim() !== ''
        );

        const feedback = campo
            .closest('.formularios-field')
            ?.querySelector('.invalid-feedback');

        if (feedback && !valido && mensaje) {
            feedback.textContent = mensaje;
        }

        return valido;
    };

    const validarNombre = function (campo, etiqueta) {
        const valor = String(campo?.value || '').trim();
        const patron = /^[\p{L}\p{M}][\p{L}\p{M} .'’-]*$/u;
        const valido =
            valor.length >= 2 &&
            valor.length <= Number(campo?.maxLength || 120) &&
            patron.test(valor);

        return marcarCampo(
            campo,
            valido,
            'Ingresa ' + etiqueta + ' válido usando únicamente letras y espacios.'
        );
    };

    const validarFecha = function () {
        const valor = String(fecha?.value || '').trim();

        if (valor === '') {
            return marcarCampo(
                fecha,
                false,
                'Selecciona una fecha de nacimiento.'
            );
        }

        const seleccionada = new Date(valor + 'T00:00:00');
        const hoy = new Date();
        hoy.setHours(0, 0, 0, 0);
        const minima = new Date('1900-01-01T00:00:00');

        return marcarCampo(
            fecha,
            !Number.isNaN(seleccionada.getTime()) &&
                seleccionada <= hoy &&
                seleccionada >= minima,
            'Selecciona una fecha de nacimiento válida.'
        );
    };

    const validarMoviles = function () {
        const principal = normalizarTelefono(movil);
        const confirmacion = normalizarTelefono(movilConfirmacion);

        const principalValido = marcarCampo(
            movil,
            /^\d{10}$/.test(principal),
            'El número móvil debe contener exactamente 10 dígitos.'
        );

        const confirmacionValida = marcarCampo(
            movilConfirmacion,
            /^\d{10}$/.test(confirmacion) &&
                principal === confirmacion,
            principal !== '' && confirmacion !== '' &&
                principal !== confirmacion
                ? 'Los números móviles no coinciden.'
                : 'Confirma el número móvil con 10 dígitos.'
        );

        return principalValido && confirmacionValida;
    };

    const validarCorreos = function () {
        const principal = String(correo?.value || '').trim().toLowerCase();
        const confirmacion = String(
            correoConfirmacion?.value || ''
        ).trim().toLowerCase();

        const formatoValido = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

        const principalValido = marcarCampo(
            correo,
            formatoValido.test(principal),
            'Ingresa un correo electrónico válido.'
        );

        const confirmacionValida = marcarCampo(
            correoConfirmacion,
            formatoValido.test(confirmacion) &&
                principal === confirmacion,
            principal !== '' && confirmacion !== '' &&
                principal !== confirmacion
                ? 'Los correos electrónicos no coinciden.'
                : 'Confirma el correo electrónico.'
        );

        return principalValido && confirmacionValida;
    };

    const validarSelect = function (campo, mensaje) {
        return marcarCampo(
            campo,
            String(campo?.value || '').trim() !== '',
            mensaje
        );
    };

    const validarTextoRequerido = function (campo, mensaje) {
        return marcarCampo(
            campo,
            String(campo?.value || '').trim().length >= 2,
            mensaje
        );
    };

    const validarFormulario = function () {
        const resultados = [
            validarNombre(nombre, 'un nombre'),
            validarNombre(apellido, 'un apellido'),
            validarFecha(),
            validarSelect(
                perfil,
                'Selecciona un perfil de interés.'
            ),
            validarMoviles(),
            validarCorreos(),
            validarSelect(
                estado,
                'Selecciona un estado.'
            ),
            validarSelect(
                municipio,
                'Selecciona un municipio.'
            )
        ];

        return resultados.every(Boolean);
    };

    const limpiarMunicipios = function (texto) {
        if (!municipio) {
            return;
        }

        municipio.innerHTML =
            '<option value="">' +
                escapar(texto || 'Selecciona un municipio') +
            '</option>';
        municipio.value = '';
        municipio.disabled = true;
        municipio.classList.remove('is-valid', 'is-invalid');
        municipio.setCustomValidity('');
    };

    const cargarMunicipios = async function () {
        if (!estado || !municipio) {
            return;
        }

        const estadoId = Number(estado.value || 0);

        if (estadoId <= 0) {
            limpiarMunicipios('Primero selecciona un estado');

            if (ayudaMunicipio) {
                ayudaMunicipio.textContent =
                    'Los municipios se cargarán según el estado seleccionado.';
            }

            return;
        }

        const urlBase = String(
            estado.getAttribute('data-municipios-url') || ''
        );

        if (urlBase === '') {
            mostrarToast(
                'No se encontró la ruta para cargar municipios.',
                true
            );
            return;
        }

        cargandoMunicipios = true;
        municipio.disabled = true;
        municipio.innerHTML =
            '<option value="">Cargando municipios...</option>';

        if (ayudaMunicipio) {
            ayudaMunicipio.textContent = 'Cargando municipios...';
        }

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

            if (ayudaMunicipio) {
                ayudaMunicipio.textContent = municipios.length > 0
                    ? municipios.length +
                        (municipios.length === 1
                            ? ' municipio disponible.'
                            : ' municipios disponibles.')
                    : 'No hay municipios activos registrados para este estado.';
            }
        } catch (error) {
            console.error(error);
            limpiarMunicipios('No fue posible cargar municipios');

            if (ayudaMunicipio) {
                ayudaMunicipio.textContent =
                    'No fue posible cargar los municipios.';
            }

            mostrarToast(
                error.message || 'No fue posible cargar los municipios.',
                true
            );
        } finally {
            cargandoMunicipios = false;
        }
    };

    estado?.addEventListener('change', function () {
        marcarCampo(estado, estado.value !== '', 'Selecciona un estado.');
        cargarMunicipios();
    });

    municipio?.addEventListener('change', function () {
        validarSelect(municipio, 'Selecciona un municipio.');
    });

    nombre?.addEventListener('blur', function () {
        validarNombre(nombre, 'un nombre');
    });

    apellido?.addEventListener('blur', function () {
        validarNombre(apellido, 'un apellido');
    });

    fecha?.addEventListener('change', validarFecha);
    perfil?.addEventListener('change', function () {
        validarSelect(perfil, 'Selecciona un perfil de interés.');
    });

    [movil, movilConfirmacion].forEach(function (campo) {
        campo?.addEventListener('input', function () {
            normalizarTelefono(campo);

            if (
                String(movil?.value || '').length === 10 ||
                String(movilConfirmacion?.value || '').length === 10
            ) {
                validarMoviles();
            }
        });

        campo?.addEventListener('blur', validarMoviles);
    });

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

    form.addEventListener('reset', function () {
        window.setTimeout(function () {
            form.querySelectorAll('.is-valid, .is-invalid')
                .forEach(function (campo) {
                    campo.classList.remove('is-valid', 'is-invalid');
                    campo.setCustomValidity('');
                });

            limpiarMunicipios('Primero selecciona un estado');

            if (ayudaMunicipio) {
                ayudaMunicipio.textContent =
                    'Los municipios se cargarán según el estado seleccionado.';
            }
        }, 0);
    });

    form.addEventListener('submit', async function (event) {
        event.preventDefault();

        if (guardando || cargandoMunicipios) {
            return;
        }

        if (!validarFormulario()) {
            const primeroInvalido = form.querySelector('.is-invalid');
            primeroInvalido?.focus();

            mostrarToast(
                'Revisa los campos marcados antes de guardar el registro.',
                true
            );
            return;
        }

        const htmlOriginal = botonSubmit?.innerHTML || '';

        guardando = true;

        if (botonSubmit) {
            botonSubmit.disabled = true;
            botonSubmit.innerHTML =
                '<span class="spinner-border spinner-border-sm" ' +
                'aria-hidden="true"></span> Guardando...';
        }

        try {
            const respuesta = await fetch(form.action, {
                method: 'POST',
                body: new FormData(form),
                headers: {
                    'X-Requested-With': 'fetch'
                }
            });

            let json = null;

            try {
                json = await respuesta.json();
            } catch (errorJson) {
                throw new Error(
                    'El servidor no devolvió una respuesta válida.'
                );
            }

            if (!respuesta.ok || !json.ok) {
                throw new Error(
                    json.mensaje ||
                    'No fue posible guardar el registro.'
                );
            }

            mostrarToast(
                json.mensaje || 'Registro guardado correctamente.',
                false
            );

            form.reset();
        } catch (error) {
            console.error(error);
            mostrarToast(
                error.message || 'No fue posible guardar el registro.',
                true
            );
        } finally {
            guardando = false;

            if (botonSubmit) {
                botonSubmit.disabled = false;
                botonSubmit.innerHTML = htmlOriginal;
            }
        }
    });
});
