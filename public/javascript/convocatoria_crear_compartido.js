document.addEventListener('DOMContentLoaded', function () {
    const classificationDropdown = document.querySelector(
        '[data-convocatoria-classification-dropdown]'
    );
    const classificationValue = classificationDropdown?.querySelector(
        '[data-convocatoria-classification-value]'
    );
    const classificationTrigger = classificationDropdown?.querySelector(
        '[data-convocatoria-classification-trigger]'
    );
    const classificationLabel = classificationDropdown?.querySelector(
        '[data-convocatoria-classification-label]'
    );
    const classificationOptions = Array.from(
        classificationDropdown?.querySelectorAll(
            '[data-convocatoria-classification-option]'
        ) || []
    );

    const closeClassificationDropdown = function () {
        classificationDropdown?.classList.remove('is-open');
        classificationTrigger?.setAttribute('aria-expanded', 'false');
    };

    classificationTrigger?.addEventListener('click', function () {
        const opening = !classificationDropdown?.classList.contains('is-open');

        if (opening) {
            classificationDropdown?.classList.add('is-open');
            classificationTrigger.setAttribute('aria-expanded', 'true');
        } else {
            closeClassificationDropdown();
        }
    });

    classificationOptions.forEach(function (option) {
        option.addEventListener('click', function () {
            const value = String(
                option.dataset.convocatoriaClassificationOption || ''
            );

            if (classificationValue) {
                classificationValue.value = value;
                classificationValue.dispatchEvent(
                    new Event('change', { bubbles: true })
                );
                classificationValue.dispatchEvent(
                    new Event('input', { bubbles: true })
                );
            }

            if (classificationLabel) {
                classificationLabel.textContent = option.textContent.trim();
            }

            classificationOptions.forEach(function (item) {
                const selected = item === option;
                item.classList.toggle('is-selected', selected);
                item.setAttribute(
                    'aria-selected',
                    selected ? 'true' : 'false'
                );
            });

            closeClassificationDropdown();
        });
    });

    document.addEventListener('click', function (event) {
        if (
            classificationDropdown &&
            !classificationDropdown.contains(event.target)
        ) {
            closeClassificationDropdown();
        }
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') {
            closeClassificationDropdown();
        }
    });

    const availabilityDropdown = document.querySelector(
        '[data-convocatoria-availability-dropdown]'
    );
    const availabilityValue = availabilityDropdown?.querySelector(
        '[data-convocatoria-availability-value]'
    );
    const availabilityTrigger = availabilityDropdown?.querySelector(
        '[data-convocatoria-availability-trigger]'
    );
    const availabilityLabel = availabilityDropdown?.querySelector(
        '[data-convocatoria-availability-label]'
    );
    const availabilityOptions = Array.from(
        availabilityDropdown?.querySelectorAll(
            '[data-convocatoria-availability-option]'
        ) || []
    );

    const closeAvailabilityDropdown = function () {
        availabilityDropdown?.classList.remove('is-open');
        availabilityTrigger?.setAttribute('aria-expanded', 'false');
    };

    availabilityTrigger?.addEventListener('click', function () {
        const opening = !availabilityDropdown?.classList.contains('is-open');

        if (opening) {
            availabilityDropdown?.classList.add('is-open');
            availabilityTrigger.setAttribute('aria-expanded', 'true');
        } else {
            closeAvailabilityDropdown();
        }
    });

    availabilityOptions.forEach(function (option) {
        option.addEventListener('click', function () {
            const value = String(
                option.dataset.convocatoriaAvailabilityOption || '1'
            );

            if (availabilityValue) {
                availabilityValue.value = value;
            }

            if (availabilityLabel) {
                availabilityLabel.textContent = option.textContent.trim();
            }

            availabilityOptions.forEach(function (item) {
                const selected = item === option;
                item.classList.toggle('is-selected', selected);
                item.setAttribute(
                    'aria-selected',
                    selected ? 'true' : 'false'
                );
            });

            closeAvailabilityDropdown();
        });
    });

    document.addEventListener('click', function (event) {
        if (
            availabilityDropdown &&
            !availabilityDropdown.contains(event.target)
        ) {
            closeAvailabilityDropdown();
        }
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') {
            closeAvailabilityDropdown();
        }
    });

    const share = document.querySelector('[data-convocatoria-form-share]');

    if (share) {
        const select = share.querySelector('[data-convocatoria-form-type]');
        const formTypeDropdown = share.querySelector('[data-convocatoria-form-type-dropdown]');
        const formTypeTrigger = share.querySelector('[data-convocatoria-form-type-trigger]');
        const formTypeLabel = share.querySelector('[data-convocatoria-form-type-label]');
        const formTypeOptions = Array.from(
            share.querySelectorAll('[data-convocatoria-form-type-value]')
        );
        const buttonLink = share.querySelector('[data-convocatoria-generate-link]');
        const buttonQr = share.querySelector('[data-convocatoria-generate-qr]');
        const result = share.querySelector('[data-convocatoria-form-result]');
        const inputUrl = share.querySelector('[data-convocatoria-form-url]');
        const copyButton = share.querySelector('[data-convocatoria-copy-link]');
        const qrModalElement = document.querySelector('[data-convocatoria-qr-modal]');
        const qrPreview = qrModalElement?.querySelector('[data-convocatoria-qr-preview]');
        const qrLoader = qrModalElement?.querySelector('[data-convocatoria-qr-loader]');
        const qrLink = qrModalElement?.querySelector('[data-convocatoria-qr-link]');
        const qrDark = qrModalElement?.querySelector('[data-convocatoria-qr-dark]');
        const qrLight = qrModalElement?.querySelector('[data-convocatoria-qr-light]');
        const qrFinderColor = qrModalElement?.querySelector('[data-convocatoria-qr-finder-color]');
        const qrDotStyle = qrModalElement?.querySelector('[data-convocatoria-qr-dot-style]');
        const qrFinderStyle = qrModalElement?.querySelector('[data-convocatoria-qr-finder-style]');
        const qrFinderDotStyle = qrModalElement?.querySelector('[data-convocatoria-qr-finder-dot-style]');
        const qrSize = qrModalElement?.querySelector('[data-convocatoria-qr-size]');
        const qrMargin = qrModalElement?.querySelector('[data-convocatoria-qr-margin]');
        const qrEcLevel = qrModalElement?.querySelector('[data-convocatoria-qr-ec-level]');
        const qrTransparent = qrModalElement?.querySelector('[data-convocatoria-qr-transparent]');
        const qrImageEnabled = qrModalElement?.querySelector('[data-convocatoria-qr-image-enabled]');
        const qrImageConfig = qrModalElement?.querySelector('[data-convocatoria-qr-image-config]');
        const qrImageSources = Array.from(
            qrModalElement?.querySelectorAll('[data-convocatoria-qr-image-source]') || []
        );
        const qrUpload = qrModalElement?.querySelector('[data-convocatoria-qr-upload]');
        const qrUploadContainer = qrModalElement?.querySelector('[data-convocatoria-qr-upload-container]');
        const qrUploadPreview = qrModalElement?.querySelector('[data-convocatoria-qr-upload-preview]');
        const qrUploadPreviewImage = qrModalElement?.querySelector('[data-convocatoria-qr-upload-preview-image]');
        const qrUploadName = qrModalElement?.querySelector('[data-convocatoria-qr-upload-name]');
        const qrUrlContainer = qrModalElement?.querySelector('[data-convocatoria-qr-url-container]');
        const qrImageUrl = qrModalElement?.querySelector('[data-convocatoria-qr-image-url]');
        const qrImageSize = qrModalElement?.querySelector('[data-convocatoria-qr-image-size]');
        const qrImageSizeValue = qrModalElement?.querySelector('[data-convocatoria-qr-image-size-value]');
        const qrReset = qrModalElement?.querySelector('[data-convocatoria-reset-qr]');
        const qrCopy = qrModalElement?.querySelector('[data-convocatoria-copy-qr]');
        const qrDownload = qrModalElement?.querySelector('[data-convocatoria-download-qr]');
        const convocatoriaLinkInput = document.querySelector('[data-convocatoria-link-input]');

        let qrCurrentUrl = '';
        let qrObjectUrl = '';
        let qrUploadedImageData = '';
        let qrRenderToken = 0;
        let qrTimer = null;

        let convocatoriaPublicRef = '';

        const buildPublicReference = function () {
            if (convocatoriaPublicRef !== '') {
                return convocatoriaPublicRef;
            }

            if (
                window.crypto &&
                typeof window.crypto.randomUUID === 'function'
            ) {
                convocatoriaPublicRef = window.crypto.randomUUID();
                return convocatoriaPublicRef;
            }

            convocatoriaPublicRef =
                'conv-' +
                Date.now().toString(36) +
                '-' +
                Math.random().toString(36).slice(2, 10);

            return convocatoriaPublicRef;
        };

        const getSelectedUrl = function () {
            const type = String(select?.value || '');
            let baseUrl = '';

            if (type === 'registro') {
                baseUrl = String(share.dataset.registroUrl || '');
            } else if (type === 'inscripcion') {
                baseUrl = String(share.dataset.inscripcionUrl || '');
            }

            if (baseUrl === '') {
                return '';
            }

            const classification = String(
                document.querySelector(
                    '[data-convocatoria-context-classification]'
                )?.value || ''
            ).trim();

            const title = String(
                document.querySelector(
                    '[data-convocatoria-context-title]'
                )?.value || ''
            ).trim();

            const aliadoId = String(
                share.dataset.aliadoId || ''
            ).trim();

            const estadoId = String(
                share.dataset.estadoId || ''
            ).trim();

            const estadoNombre = String(
                share.dataset.estadoNombre || ''
            ).trim();

            const convenioNombre = String(
                share.dataset.convenioNombre || ''
            ).trim();

            const url = new URL(baseUrl, window.location.origin);
            if (share.dataset.referenciaInternacional) {
                url.searchParams.set('convocatoria_ref', share.dataset.referenciaInternacional);
                return url.toString();
            }

            url.searchParams.set('formulario', type);
            url.searchParams.set('origen', 'convenio_aliado');

            if (convenioNombre !== '') {
                url.searchParams.set('convenio', convenioNombre);
            }

            if (estadoId !== '' && estadoId !== '0') {
                url.searchParams.set('estado_id', estadoId);
            }

            if (estadoNombre !== '') {
                url.searchParams.set('estado', estadoNombre);
            }

            if (classification !== '') {
                url.searchParams.set('convocatoria', classification);
            }

            if (title !== '') {
                url.searchParams.set('titulo', title);
            }

            url.searchParams.set(
                'convocatoria_ref',
                buildPublicReference()
            );

            return url.toString();
        };

        const canGeneratePublicLink = function () {
            const type = String(select?.value || '').trim();
            const classification = String(
                document.querySelector(
                    '[data-convocatoria-context-classification]'
                )?.value || ''
            ).trim();
            const title = String(
                document.querySelector(
                    '[data-convocatoria-context-title]'
                )?.value || ''
            ).trim();
            const aliadoId = String(
                share.dataset.aliadoId || ''
            ).trim();
            const estadoId = String(
                share.dataset.estadoId || ''
            ).trim();

            return (
                (type === 'registro' || type === 'inscripcion') &&
                classification !== '' &&
                (Boolean(share.dataset.referenciaInternacional) || (estadoId !== '' && estadoId !== '0'))
            );
        };

        const updatePublicLinkAvailability = function () {
            if (buttonLink) {
                buttonLink.disabled = false;
                buttonLink.setAttribute(
                    'aria-disabled',
                    canGeneratePublicLink() ? 'false' : 'true'
                );
            }
        };

        const resetShare = function () {
            result?.classList.add('d-none');
            if (inputUrl) {
                inputUrl.value = '';
            }

            if (buttonQr) {
                buttonQr.disabled = true;
            }
        };

        const closeFormTypeDropdown = function () {
            formTypeDropdown?.classList.remove('is-open');
            formTypeTrigger?.setAttribute('aria-expanded', 'false');
        };

        formTypeTrigger?.addEventListener('click', function () {
            const opening = !formTypeDropdown?.classList.contains('is-open');

            if (opening) {
                formTypeDropdown?.classList.add('is-open');
                formTypeTrigger?.setAttribute('aria-expanded', 'true');
            } else {
                closeFormTypeDropdown();
            }
        });

        formTypeOptions.forEach(function (option) {
            option.addEventListener('click', function () {
                const value = String(
                    option.dataset.convocatoriaFormTypeValue || ''
                );

                if (select) {
                    select.value = value;
                }

                if (formTypeLabel) {
                    formTypeLabel.textContent = option.textContent.trim();
                }

                formTypeOptions.forEach(function (item) {
                    const selected = item === option;
                    item.classList.toggle('is-selected', selected);
                    item.setAttribute(
                        'aria-selected',
                        selected ? 'true' : 'false'
                    );
                });

                closeFormTypeDropdown();

                resetShare();
                updatePublicLinkAvailability();
            });
        });

        document.addEventListener('click', function (event) {
            if (
                formTypeDropdown &&
                !formTypeDropdown.contains(event.target)
            ) {
                closeFormTypeDropdown();
            }
        });

        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') {
                closeFormTypeDropdown();
            }
        });

        const contextFields = [
            document.querySelector(
                '[data-convocatoria-context-classification]'
            )
        ];

        contextFields.forEach(function (field) {
            const invalidateGeneratedLink = function () {
                if (!result?.classList.contains('d-none')) {
                    resetShare();
                }

                updatePublicLinkAvailability();
            };

            field?.addEventListener('input', invalidateGeneratedLink);
            field?.addEventListener('change', invalidateGeneratedLink);
        });

        buttonLink?.addEventListener('click', async function () {
            if (!canGeneratePublicLink()) {
                showQrToast(
                    'Selecciona el formulario y el tipo de convocatoria antes de generar el enlace.',
                    true
                );
                return;
            }

            let url;
            const selectedUrl = getSelectedUrl();
            try { url = await window.IMPERegistroEnlaces.acortar(selectedUrl); }
            catch(error) { showQrToast(error.message, true); return; }
            if (selectedUrl !== getSelectedUrl()) return;

            if (url === '') {
                return;
            }

            if (inputUrl) {
                inputUrl.value = url;
            }

            if (convocatoriaLinkInput) {
                convocatoriaLinkInput.value = url;
                convocatoriaLinkInput.dispatchEvent(
                    new Event('input', { bubbles: true })
                );
            }

            result?.classList.remove('d-none');

            if (buttonQr) {
                buttonQr.disabled = false;
            }
        });

        const showQrToast = function (message, error) {
            let container = document.querySelector('[data-convocatoria-copy-toast-container]');

            if (!container) {
                container = document.createElement('div');
                container.className = 'toast-container position-fixed top-0 end-0 p-3';
                container.setAttribute('data-convocatoria-copy-toast-container', '');
                container.style.zIndex = '1200';
                document.body.appendChild(container);
            }

            const toast = document.createElement('div');
            toast.className = 'toast system-toast' + (error ? ' system-toast-error' : '');
            toast.setAttribute('role', error ? 'alert' : 'status');
            toast.innerHTML =
                '<div class="toast-body"><i class="bi ' +
                (error ? 'bi-exclamation-circle' : 'bi-check2-circle') +
                '"></i><span></span></div>';
            toast.querySelector('span').textContent = message;
            container.appendChild(toast);
            toast.addEventListener('hidden.bs.toast', function () {
                toast.remove();
            });

            bootstrap.Toast.getOrCreateInstance(toast, {
                autohide: true,
                delay: error ? 4200 : 2600
            }).show();
        };

        const normalizeQrColor = function (value, fallback) {
            const clean = String(value || '').replace('#', '').trim();
            return /^[0-9a-fA-F]{6}$/.test(clean)
                ? clean.toLowerCase()
                : fallback;
        };

        const getQrImageSource = function () {
            return qrImageSources.find(function (radio) {
                return radio.checked;
            })?.value || 'upload';
        };

        const getQrCenterImage = function () {
            if (!qrImageEnabled?.checked) {
                return '';
            }

            if (getQrImageSource() === 'upload') {
                if (qrUploadedImageData === '') {
                    throw new Error('Selecciona una imagen desde tu equipo.');
                }
                return qrUploadedImageData;
            }

            const value = String(qrImageUrl?.value || '').trim();

            if (!/^https?:\/\//i.test(value)) {
                throw new Error('Ingresa una URL pública válida para la imagen.');
            }

            return value;
        };

        const getQrParams = function () {
            const url = String(inputUrl?.value || getSelectedUrl()).trim();

            if (url === '') {
                return null;
            }

            const dark = normalizeQrColor(qrDark?.value, '223a84');
            const light = qrTransparent?.checked
                ? '0000'
                : normalizeQrColor(qrLight?.value, 'ffffff');
            const finderColor = normalizeQrColor(qrFinderColor?.value, dark);

            if (!qrTransparent?.checked && dark === light) {
                throw new Error('El color del QR y el fondo deben ser diferentes.');
            }

            const centerImage = getQrCenterImage();

            const params = {
                text: url,
                size: String(qrSize?.value || '600'),
                margin: String(qrMargin?.value || '2'),
                dark: dark,
                light: light,
                finderColor: finderColor,
                dotStyle: String(qrDotStyle?.value || 'square'),
                finderStyle: String(qrFinderStyle?.value || 'square'),
                finderDotStyle: String(qrFinderDotStyle?.value || 'square'),
                ecLevel: centerImage !== '' ? 'H' : String(qrEcLevel?.value || 'M'),
                format: 'png'
            };

            if (centerImage !== '') {
                const imagePercent = Math.min(
                    30,
                    Math.max(10, Number(qrImageSize?.value || 20))
                );
                params.centerImageUrl = centerImage;
                params.centerImageSizeRatio = Number((imagePercent / 100).toFixed(2));
            }

            return params;
        };

        const revokeQrObjectUrl = function () {
            if (qrObjectUrl !== '') {
                URL.revokeObjectURL(qrObjectUrl);
                qrObjectUrl = '';
            }
        };

        const updateQrPreview = async function () {
            if (!qrPreview) {
                return;
            }

            const token = ++qrRenderToken;

            try {
                const params = getQrParams();

                if (!params) {
                    return;
                }

                const quickchartBase = String(
                    share.dataset.quickchartUrl || 'https://quickchart.io/qr'
                ).trim();

                qrLoader?.classList.remove('d-none');
                qrPreview.classList.add('is-loading');

                const usingLocalImage =
                    qrImageEnabled?.checked &&
                    getQrImageSource() === 'upload';

                if (usingLocalImage) {
                    const response = await fetch(quickchartBase, {
                        method: 'POST',
                        mode: 'cors',
                        cache: 'no-store',
                        headers: {
                            'Content-Type': 'application/json'
                        },
                        body: JSON.stringify(params)
                    });

                    if (!response.ok) {
                        throw new Error('No fue posible generar el QR con la imagen seleccionada.');
                    }

                    const blob = await response.blob();

                    if (token !== qrRenderToken) {
                        return;
                    }

                    revokeQrObjectUrl();
                    qrObjectUrl = URL.createObjectURL(blob);
                    qrCurrentUrl = qrObjectUrl;
                    qrPreview.src = qrCurrentUrl;
                } else {
                    revokeQrObjectUrl();

                    const query = new URLSearchParams();
                    Object.entries(params).forEach(function ([key, value]) {
                        query.set(key, String(value));
                    });

                    qrCurrentUrl = quickchartBase + '?' + query.toString();
                    qrPreview.src = qrCurrentUrl;
                }

                if (qrLink) {
                    qrLink.textContent = String(inputUrl?.value || '');
                }
            } catch (error) {
                qrLoader?.classList.add('d-none');
                qrPreview.classList.remove('is-loading');
                showQrToast(
                    error.message || 'No fue posible actualizar el código QR.',
                    true
                );
            }
        };

        const scheduleQrUpdate = function (delay) {
            window.clearTimeout(qrTimer);
            qrTimer = window.setTimeout(updateQrPreview, delay ?? 120);
        };

        const getQrBlob = async function () {
            if (qrCurrentUrl === '') {
                await updateQrPreview();
            }

            if (qrCurrentUrl === '') {
                throw new Error('No hay un código QR disponible.');
            }

            const response = await fetch(qrCurrentUrl, {
                mode: 'cors',
                cache: 'no-store'
            });

            if (!response.ok) {
                throw new Error('No fue posible obtener la imagen del QR.');
            }

            return await response.blob();
        };

        const resetQr = function () {
            if (qrDark) qrDark.value = '#223a84';
            if (qrLight) {
                qrLight.value = '#ffffff';
                qrLight.disabled = false;
            }
            if (qrFinderColor) qrFinderColor.value = '#223a84';
            if (qrDotStyle) qrDotStyle.value = 'square';
            if (qrFinderStyle) qrFinderStyle.value = 'square';
            if (qrFinderDotStyle) qrFinderDotStyle.value = 'square';
            if (qrSize) qrSize.value = '600';
            if (qrMargin) qrMargin.value = '2';
            if (qrEcLevel) qrEcLevel.value = 'M';
            if (qrTransparent) qrTransparent.checked = false;
            if (qrImageEnabled) qrImageEnabled.checked = false;

            qrImageConfig?.classList.add('d-none');
            qrUploadContainer?.classList.remove('d-none');
            qrUrlContainer?.classList.add('d-none');

            qrImageSources.forEach(function (radio) {
                radio.checked = radio.value === 'upload';
            });

            qrUploadedImageData = '';

            if (qrUpload) qrUpload.value = '';
            if (qrUploadPreviewImage) qrUploadPreviewImage.src = '';
            if (qrUploadName) qrUploadName.textContent = '';
            qrUploadPreview?.classList.add('d-none');
            if (qrImageUrl) qrImageUrl.value = '';
            if (qrImageSize) qrImageSize.value = '20';
            if (qrImageSizeValue) qrImageSizeValue.textContent = '20%';

            updateQrPreview();
        };

        buttonQr?.addEventListener('click', function () {
            const url = String(inputUrl?.value || getSelectedUrl()).trim();

            if (url === '' || !qrModalElement) {
                return;
            }

            updateQrPreview();
            bootstrap.Modal.getOrCreateInstance(qrModalElement).show();
        });

        [
            qrDark,
            qrLight,
            qrFinderColor,
            qrDotStyle,
            qrFinderStyle,
            qrFinderDotStyle,
            qrSize,
            qrMargin,
            qrEcLevel
        ].forEach(function (control) {
            control?.addEventListener('input', function () {
                scheduleQrUpdate(80);
            });
            control?.addEventListener('change', function () {
                scheduleQrUpdate(0);
            });
        });

        qrTransparent?.addEventListener('change', function () {
            if (qrLight) {
                qrLight.disabled = qrTransparent.checked;
            }
            scheduleQrUpdate(0);
        });

        qrImageEnabled?.addEventListener('change', function () {
            qrImageConfig?.classList.toggle('d-none', !qrImageEnabled.checked);

            if (!qrImageEnabled.checked) {
                scheduleQrUpdate(0);
                return;
            }

            const hasImage =
                (getQrImageSource() === 'upload' && qrUploadedImageData !== '') ||
                (getQrImageSource() === 'url' && String(qrImageUrl?.value || '').trim() !== '');

            if (hasImage) {
                scheduleQrUpdate(0);
            }
        });

        qrImageSources.forEach(function (radio) {
            radio.addEventListener('change', function () {
                const upload = getQrImageSource() === 'upload';
                qrUploadContainer?.classList.toggle('d-none', !upload);
                qrUrlContainer?.classList.toggle('d-none', upload);

                if (qrImageEnabled?.checked) {
                    scheduleQrUpdate(0);
                }
            });
        });

        qrUpload?.addEventListener('change', function () {
            const file = qrUpload.files?.[0];

            if (!file) {
                qrUploadedImageData = '';
                qrUploadPreview?.classList.add('d-none');
                return;
            }

            if (!/^image\/(png|jpeg|webp)$/i.test(file.type)) {
                qrUpload.value = '';
                showQrToast('Selecciona una imagen PNG, JPG o WEBP.', true);
                return;
            }

            const reader = new FileReader();

            reader.onload = function () {
                qrUploadedImageData = String(reader.result || '');
                if (qrUploadPreviewImage) qrUploadPreviewImage.src = qrUploadedImageData;
                if (qrUploadName) qrUploadName.textContent = file.name;
                qrUploadPreview?.classList.remove('d-none');

                if (qrImageEnabled?.checked) {
                    scheduleQrUpdate(0);
                }
            };

            reader.readAsDataURL(file);
        });

        qrImageUrl?.addEventListener('input', function () {
            if (qrImageEnabled?.checked && getQrImageSource() === 'url') {
                scheduleQrUpdate(300);
            }
        });

        qrImageSize?.addEventListener('input', function () {
            const value = Math.min(30, Math.max(10, Number(qrImageSize.value || 20)));

            if (qrImageSizeValue) {
                qrImageSizeValue.textContent = value + '%';
            }

            if (qrImageEnabled?.checked) {
                scheduleQrUpdate(80);
            }
        });

        qrReset?.addEventListener('click', resetQr);

        qrDownload?.addEventListener('click', async function () {
            try {
                const blob = await getQrBlob();
                const objectUrl = URL.createObjectURL(blob);
                const a = document.createElement('a');
                const type = String(select?.value || 'formulario');
                a.href = objectUrl;
                a.download = 'qr-' + type + '.png';
                document.body.appendChild(a);
                a.click();
                a.remove();
                window.setTimeout(function () {
                    URL.revokeObjectURL(objectUrl);
                }, 1000);
                showQrToast('QR descargado correctamente.', false);
            } catch (error) {
                showQrToast(error.message || 'No fue posible descargar el QR.', true);
            }
        });

        qrCopy?.addEventListener('click', async function () {
            try {
                const blob = await getQrBlob();

                if (!navigator.clipboard || typeof ClipboardItem === 'undefined') {
                    throw new Error('Tu navegador no permite copiar imágenes directamente.');
                }

                await navigator.clipboard.write([
                    new ClipboardItem({ 'image/png': blob })
                ]);

                showQrToast('QR copiado correctamente.', false);
            } catch (error) {
                showQrToast(
                    error.message || 'No fue posible copiar el QR.',
                    true
                );
            }
        });

        qrPreview?.addEventListener('load', function () {
            qrLoader?.classList.add('d-none');
            qrPreview.classList.remove('is-loading');
        });

        qrPreview?.addEventListener('error', function () {
            qrLoader?.classList.add('d-none');
            qrPreview.classList.remove('is-loading');
        });

        qrModalElement?.addEventListener('hidden.bs.modal', function () {
            window.clearTimeout(qrTimer);
        });

        const mostrarToastCopia = function () {
            let container = document.querySelector(
                '[data-convocatoria-copy-toast-container]'
            );

            if (!container) {
                container = document.createElement('div');
                container.className =
                    'toast-container position-fixed top-0 end-0 p-3';
                container.setAttribute(
                    'data-convocatoria-copy-toast-container',
                    ''
                );
                container.style.zIndex = '1100';
                document.body.appendChild(container);
            }

            const toast = document.createElement('div');
            toast.className = 'toast system-toast';
            toast.setAttribute('role', 'status');
            toast.setAttribute('aria-live', 'polite');
            toast.setAttribute('aria-atomic', 'true');

            toast.innerHTML =
                '<div class="toast-body">' +
                    '<i class="bi bi-check2-circle"></i>' +
                    '<span>Enlace copiado correctamente.</span>' +
                '</div>';

            container.appendChild(toast);

            toast.addEventListener('hidden.bs.toast', function () {
                toast.remove();
            });

            if (window.bootstrap) {
                bootstrap.Toast.getOrCreateInstance(toast, {
                    autohide: true,
                    delay: 2600
                }).show();
            }
        };

        copyButton?.addEventListener('click', async function () {
            const url = String(inputUrl?.value || '').trim();

            if (url === '') {
                return;
            }

            try {
                await navigator.clipboard.writeText(url);
                mostrarToastCopia();
            } catch (error) {
                inputUrl?.focus();
                inputUrl?.select();
                document.execCommand('copy');
                mostrarToastCopia();
            }
        });
    }

    const auto = document.querySelector('[data-convocatoria-auto-open]');
    if (auto && window.bootstrap) { bootstrap.Modal.getOrCreateInstance(auto).show(); }
});
