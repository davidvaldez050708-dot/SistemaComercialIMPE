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
    const telRecomendado = form.querySelector(
        '[data-registro-tel-recomendado]'
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
    const modalQrElement = document.querySelector(
        '[data-form-qr-modal]'
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
    const qrDark = modalQrElement?.querySelector('[data-qr-dark]');
    const qrLight = modalQrElement?.querySelector('[data-qr-light]');
    const qrFinderColor = modalQrElement?.querySelector(
        '[data-qr-finder-color]'
    );
    const qrDotStyle = modalQrElement?.querySelector(
        '[data-qr-dot-style]'
    );
    const qrFinderStyle = modalQrElement?.querySelector(
        '[data-qr-finder-style]'
    );
    const qrFinderDotStyle = modalQrElement?.querySelector(
        '[data-qr-finder-dot-style]'
    );
    const qrSize = modalQrElement?.querySelector('[data-qr-size]');
    const qrMargin = modalQrElement?.querySelector('[data-qr-margin]');
    const qrEcLevel = modalQrElement?.querySelector('[data-qr-ec-level]');
    const qrTransparent = modalQrElement?.querySelector(
        '[data-qr-transparent]'
    );
    const qrImageEnabled = modalQrElement?.querySelector(
        '[data-qr-image-enabled]'
    );
    const qrImageConfig = modalQrElement?.querySelector(
        '[data-qr-image-config]'
    );
    const qrImageSources = modalQrElement?.querySelectorAll(
        '[data-qr-image-source]'
    ) || [];
    const qrUploadImage = modalQrElement?.querySelector(
        '[data-qr-upload-image]'
    );
    const qrUploadImageContainer = modalQrElement?.querySelector(
        '[data-qr-upload-image-container]'
    );
    const qrUploadPreview = modalQrElement?.querySelector(
        '[data-qr-upload-preview]'
    );
    const qrUploadPreviewImage = modalQrElement?.querySelector(
        '[data-qr-upload-preview-image]'
    );
    const qrUploadFileName = modalQrElement?.querySelector(
        '[data-qr-upload-file-name]'
    );
    const qrPublicImageContainer = modalQrElement?.querySelector(
        '[data-qr-public-image-container]'
    );
    const qrPublicImageUrl = modalQrElement?.querySelector(
        '[data-qr-public-image-url]'
    );
    const qrImageSize = modalQrElement?.querySelector(
        '[data-qr-image-size]'
    );
    const qrImageSizeValue = modalQrElement?.querySelector(
        '[data-qr-image-size-value]'
    );
    const botonAplicarQr = modalQrElement?.querySelector(
        '[data-apply-form-qr]'
    );
    const botonRestablecerQr = modalQrElement?.querySelector(
        '[data-reset-form-qr]'
    );
    const botonCopiarQr = modalQrElement?.querySelector(
        '[data-copy-form-qr]'
    );
    const botonDescargarQr = modalQrElement?.querySelector(
        '[data-download-form-qr]'
    );

    let cargandoMunicipios = false;
    let guardando = false;
    let generandoLink = false;
    let enlacePublico = String(
        compartir?.getAttribute('data-current-link') || ''
    ).trim();
    const nombreFormulario = String(
        compartir?.getAttribute('data-form-label') || 'Registro'
    ).trim();
    const colorQrInicial = String(
        compartir?.getAttribute('data-qr-color') || '223A84'
    ).replace('#', '').trim() || '223A84';
    let qrUrlActual = '';
    let qrObjectUrlActual = '';
    let qrUploadedImageData = '';
    let qrRenderToken = 0;

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
                    'El enlace apunta al formulario público de ' +
                    nombreFormulario + '.';
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

    const normalizarColorQr = function (valor, fallback) {
        const limpio = String(valor || '')
            .replace('#', '')
            .trim();

        return /^[0-9a-fA-F]{6}$/.test(limpio)
            ? limpio.toLowerCase()
            : fallback;
    };

    const obtenerFuenteImagenQr = function () {
        return Array.from(qrImageSources).find(
            (radio) => radio.checked
        )?.value || 'upload';
    };

    const obtenerImagenCentralQr = function () {
        if (!qrImageEnabled?.checked) {
            return '';
        }

        const source = obtenerFuenteImagenQr();

        if (source === 'upload') {
            if (qrUploadedImageData === '') {
                throw new Error(
                    'Selecciona una imagen PNG o JPG desde tu equipo.'
                );
            }

            return qrUploadedImageData;
        }

        const valor = String(qrPublicImageUrl?.value || '').trim();

        if (valor === '') {
            throw new Error(
                'Ingresa una URL pública para la imagen del QR.'
            );
        }

        try {
            const url = new URL(valor);

            if (!['http:', 'https:'].includes(url.protocol)) {
                throw new Error();
            }

            return url.toString();
        } catch (error) {
            throw new Error(
                'La URL de la imagen no es válida.'
            );
        }
    };

    const obtenerConfiguracionQr = function () {
        const dark = normalizarColorQr(
            qrDark?.value,
            colorQrInicial.toLowerCase()
        );
        const light = qrTransparent?.checked
            ? '0000'
            : normalizarColorQr(qrLight?.value, 'ffffff');
        const finderColor = normalizarColorQr(
            qrFinderColor?.value,
            dark
        );

        if (!qrTransparent?.checked && dark === light) {
            throw new Error(
                'El color del QR y el fondo deben ser diferentes.'
            );
        }

        return {
            dark: dark,
            light: light,
            finderColor: finderColor,
            dotStyle: String(qrDotStyle?.value || 'square'),
            finderStyle: String(qrFinderStyle?.value || 'square'),
            finderDotStyle: String(
                qrFinderDotStyle?.value || 'square'
            ),
            size: String(qrSize?.value || '600'),
            margin: String(qrMargin?.value || '2'),
            ecLevel: String(qrEcLevel?.value || 'M')
        };
    };

    const construirParametrosQr = function () {
        if (enlacePublico === '' || !compartir) {
            return null;
        }

        const config = obtenerConfiguracionQr();
        const imagenCentral = obtenerImagenCentralQr();

        const parametros = {
            text: enlacePublico,
            size: config.size,
            margin: config.margin,
            dark: config.dark,
            light: config.light,
            finderColor: config.finderColor,
            dotStyle: config.dotStyle,
            finderStyle: config.finderStyle,
            finderDotStyle: config.finderDotStyle,
            ecLevel: imagenCentral !== '' ? 'H' : config.ecLevel,
            format: 'png'
        };

        if (imagenCentral !== '') {
            const porcentajeImagen = Math.min(
                30,
                Math.max(10, Number(qrImageSize?.value || 20))
            );

            parametros.centerImageUrl = imagenCentral;
            parametros.centerImageSizeRatio =
                Number((porcentajeImagen / 100).toFixed(2));
        }

        return parametros;
    };

    const limpiarQrObjectUrl = function () {
        if (qrObjectUrlActual !== '') {
            URL.revokeObjectURL(qrObjectUrlActual);
            qrObjectUrlActual = '';
        }
    };

    const actualizarPreviewQr = async function () {
        if (!imagenQr || !compartir) {
            return;
        }

        const renderToken = ++qrRenderToken;

        try {
            const quickChartBase = String(
                compartir.getAttribute('data-quickchart-url') ||
                'https://quickchart.io/qr'
            ).trim();
            const parametros = construirParametrosQr();

            if (!parametros) {
                return;
            }

            loaderQr?.classList.remove('d-none');
            imagenQr.classList.add('is-loading');

            const usaImagenLocal =
                qrImageEnabled?.checked &&
                obtenerFuenteImagenQr() === 'upload';

            if (usaImagenLocal) {
                const respuesta = await fetch(quickChartBase, {
                    method: 'POST',
                    mode: 'cors',
                    cache: 'no-store',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify(parametros)
                });

                if (!respuesta.ok) {
                    throw new Error(
                        'No fue posible generar el QR con la imagen seleccionada.'
                    );
                }

                const blob = await respuesta.blob();

                if (renderToken !== qrRenderToken) {
                    return;
                }

                limpiarQrObjectUrl();
                qrObjectUrlActual = URL.createObjectURL(blob);
                qrUrlActual = qrObjectUrlActual;
                imagenQr.src = qrUrlActual;
            } else {
                limpiarQrObjectUrl();

                const query = new URLSearchParams();

                Object.entries(parametros).forEach(function ([clave, valor]) {
                    query.set(clave, String(valor));
                });

                qrUrlActual = quickChartBase + '?' + query.toString();
                imagenQr.src = qrUrlActual;
            }

            if (enlaceQr) {
                enlaceQr.textContent = enlacePublico;
            }
        } catch (error) {
            loaderQr?.classList.add('d-none');
            imagenQr.classList.remove('is-loading');
            mostrarToast(
                error.message ||
                'No fue posible aplicar la personalización del QR.',
                true
            );
        }
    };

    const restablecerQr = function () {
        const colorInicial = '#' + colorQrInicial.toLowerCase();

        if (qrDark) {
            qrDark.value = colorInicial;
        }

        if (qrLight) {
            qrLight.value = '#ffffff';
            qrLight.disabled = false;
        }

        if (qrFinderColor) {
            qrFinderColor.value = colorInicial;
        }

        if (qrDotStyle) {
            qrDotStyle.value = 'square';
        }

        if (qrFinderStyle) {
            qrFinderStyle.value = 'square';
        }

        if (qrFinderDotStyle) {
            qrFinderDotStyle.value = 'square';
        }

        if (qrSize) {
            qrSize.value = '600';
        }

        if (qrMargin) {
            qrMargin.value = '2';
        }

        if (qrEcLevel) {
            qrEcLevel.value = 'M';
        }

        if (qrTransparent) {
            qrTransparent.checked = false;
        }

        if (qrImageEnabled) {
            qrImageEnabled.checked = false;
        }

        qrImageConfig?.classList.add('d-none');
        qrUploadImageContainer?.classList.remove('d-none');
        qrPublicImageContainer?.classList.add('d-none');

        qrImageSources.forEach(function (radio) {
            radio.checked = radio.value === 'upload';
        });

        qrUploadedImageData = '';

        if (qrUploadImage) {
            qrUploadImage.value = '';
        }

        if (qrUploadPreviewImage) {
            qrUploadPreviewImage.src = '';
        }

        if (qrUploadFileName) {
            qrUploadFileName.textContent = '';
        }

        qrUploadPreview?.classList.add('d-none');

        if (qrPublicImageUrl) {
            qrPublicImageUrl.value = '';
        }

        if (qrImageSize) {
            qrImageSize.value = '20';
        }

        if (qrImageSizeValue) {
            qrImageSizeValue.textContent = '20%';
        }

        actualizarPreviewQr();
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

        actualizarPreviewQr();

        bootstrap.Modal.getOrCreateInstance(
            modalQrElement
        ).show();
    };

    const obtenerQrBlob = async function () {
        if (qrUrlActual === '') {
            actualizarPreviewQr();
        }

        if (qrUrlActual === '') {
            throw new Error('No hay un código QR disponible.');
        }

        const respuesta = await fetch(qrUrlActual, {
            mode: 'cors',
            cache: 'no-store'
        });

        if (!respuesta.ok) {
            throw new Error(
                'No fue posible obtener la imagen del código QR.'
            );
        }

        return await respuesta.blob();
    };

    const descargarQr = async function () {
        try {
            const blob = await obtenerQrBlob();
            const objetoUrl = URL.createObjectURL(blob);
            const enlace = document.createElement('a');
            const nombre = nombreFormulario
                .toLowerCase()
                .normalize('NFD')
                .replace(/[\u0300-\u036f]/g, '')
                .replace(/[^a-z0-9]+/g, '-')
                .replace(/^-|-$/g, '');

            enlace.href = objetoUrl;
            enlace.download = 'qr-' + (nombre || 'formulario') + '.png';
            document.body.appendChild(enlace);
            enlace.click();
            enlace.remove();

            window.setTimeout(function () {
                URL.revokeObjectURL(objetoUrl);
            }, 1000);

            mostrarToast(
                'Código QR descargado correctamente.',
                false
            );
        } catch (error) {
            console.error(error);
            mostrarToast(
                error.message ||
                'No fue posible descargar el código QR.',
                true
            );
        }
    };

    const copiarQr = async function () {
        try {
            if (
                !navigator.clipboard ||
                typeof window.ClipboardItem === 'undefined'
            ) {
                throw new Error(
                    'Tu navegador no permite copiar imágenes directamente. Puedes descargar el QR.'
                );
            }

            const blob = await obtenerQrBlob();
            const pngBlob = blob.type === 'image/png'
                ? blob
                : new Blob([blob], { type: 'image/png' });

            await navigator.clipboard.write([
                new ClipboardItem({
                    'image/png': pngBlob
                })
            ]);

            mostrarToast(
                'Código QR copiado como imagen.',
                false
            );
        } catch (error) {
            console.error(error);
            mostrarToast(
                error.message ||
                'No fue posible copiar el código QR.',
                true
            );
        }
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
    botonAplicarQr?.addEventListener(
        'click',
        actualizarPreviewQr
    );
    botonRestablecerQr?.addEventListener(
        'click',
        restablecerQr
    );
    botonDescargarQr?.addEventListener(
        'click',
        descargarQr
    );
    botonCopiarQr?.addEventListener(
        'click',
        copiarQr
    );

    qrTransparent?.addEventListener('change', function () {
        if (qrLight) {
            qrLight.disabled = qrTransparent.checked;
        }
    });

    const actualizarFuenteImagenQr = function () {
        const source = obtenerFuenteImagenQr();

        qrUploadImageContainer?.classList.toggle(
            'd-none',
            source !== 'upload'
        );
        qrPublicImageContainer?.classList.toggle(
            'd-none',
            source !== 'url'
        );
    };

    qrImageEnabled?.addEventListener('change', function () {
        qrImageConfig?.classList.toggle(
            'd-none',
            !qrImageEnabled.checked
        );
        actualizarFuenteImagenQr();

        if (!qrImageEnabled.checked) {
            actualizarPreviewQr();
            return;
        }

        const source = obtenerFuenteImagenQr();

        if (
            (source === 'upload' && qrUploadedImageData !== '') ||
            (source === 'url' &&
                String(qrPublicImageUrl?.value || '').trim() !== '')
        ) {
            actualizarPreviewQr();
        }
    });

    qrImageSources.forEach(function (radio) {
        radio.addEventListener('change', function () {
            actualizarFuenteImagenQr();

            if (!radio.checked || !qrImageEnabled?.checked) {
                return;
            }

            if (
                (radio.value === 'upload' &&
                    qrUploadedImageData !== '') ||
                (radio.value === 'url' &&
                    String(qrPublicImageUrl?.value || '').trim() !== '')
            ) {
                actualizarPreviewQr();
            }
        });
    });

    qrUploadImage?.addEventListener('change', function () {
        const archivo = qrUploadImage.files?.[0] || null;

        if (!archivo) {
            qrUploadedImageData = '';
            qrUploadPreview?.classList.add('d-none');

            if (qrUploadPreviewImage) {
                qrUploadPreviewImage.src = '';
            }

            if (qrUploadFileName) {
                qrUploadFileName.textContent = '';
            }

            return;
        }

        if (!['image/png', 'image/jpeg'].includes(archivo.type)) {
            qrUploadImage.value = '';
            qrUploadedImageData = '';
            qrUploadPreview?.classList.add('d-none');
            mostrarToast(
                'Selecciona una imagen en formato PNG o JPG.',
                true
            );
            return;
        }

        if (archivo.size > 5 * 1024 * 1024) {
            qrUploadImage.value = '';
            qrUploadedImageData = '';
            qrUploadPreview?.classList.add('d-none');
            mostrarToast(
                'La imagen no debe superar 5 MB.',
                true
            );
            return;
        }

        const lector = new FileReader();

        lector.onload = function () {
            qrUploadedImageData = String(lector.result || '');

            if (qrUploadPreviewImage) {
                qrUploadPreviewImage.src = qrUploadedImageData;
            }

            if (qrUploadFileName) {
                qrUploadFileName.textContent = archivo.name;
            }

            qrUploadPreview?.classList.remove('d-none');

            if (
                qrImageEnabled?.checked &&
                obtenerFuenteImagenQr() === 'upload'
            ) {
                actualizarPreviewQr();
            }
        };

        lector.onerror = function () {
            qrUploadedImageData = '';
            qrUploadPreview?.classList.add('d-none');
            mostrarToast(
                'No fue posible leer la imagen seleccionada.',
                true
            );
        };

        lector.readAsDataURL(archivo);
    });

    qrPublicImageUrl?.addEventListener('change', function () {
        if (
            qrImageEnabled?.checked &&
            obtenerFuenteImagenQr() === 'url'
        ) {
            actualizarPreviewQr();
        }
    });

    qrImageSize?.addEventListener('input', function () {
        const valor = Math.min(
            30,
            Math.max(10, Number(qrImageSize.value || 20))
        );

        if (qrImageSizeValue) {
            qrImageSizeValue.textContent = valor + '%';
        }

        if (!qrImageEnabled?.checked) {
            return;
        }

        const source = obtenerFuenteImagenQr();
        const hayImagen =
            (source === 'upload' && qrUploadedImageData !== '') ||
            (source === 'url' &&
                String(qrPublicImageUrl?.value || '').trim() !== '');

        if (hayImagen) {
            actualizarPreviewQr();
        }
    });

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

    const validarTelRecomendado = function () {
        if (!telRecomendado) {
            return true;
        }

        const valor = normalizarTelefono(telRecomendado);

        if (valor === '') {
            telRecomendado.setCustomValidity('');
            telRecomendado.classList.remove('is-valid', 'is-invalid');
            return true;
        }

        return marcarCampo(
            telRecomendado,
            /^\d{10}$/.test(valor),
            'El teléfono recomendado debe contener exactamente 10 dígitos.'
        );
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
            validarTelRecomendado(),
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

    telRecomendado?.addEventListener('input', function () {
        normalizarTelefono(telRecomendado);

        if (
            String(telRecomendado.value || '').length === 10 ||
            String(telRecomendado.value || '').length === 0
        ) {
            validarTelRecomendado();
        }
    });

    telRecomendado?.addEventListener(
        'blur',
        validarTelRecomendado
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
