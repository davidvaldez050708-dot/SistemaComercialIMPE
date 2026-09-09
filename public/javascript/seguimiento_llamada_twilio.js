(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        const offcanvas = document.getElementById('offcanvasSeguimientoTrabajo');
        const rolId = Number(window.IMPE_CURRENT_ROLE_ID || 0);

        if (!offcanvas || rolId !== 4) {
            return;
        }

        const tokenUrl = 'prueba_telefonia/api/token.php';
        const estadoUrl = 'prueba_telefonia/api/estado_llamada.php';
        const vincularUrl = 'prueba_telefonia/api/vincular_interaccion.php';
        const sdkUrl = 'https://cdn.jsdelivr.net/npm/@twilio/voice-sdk@2.0.1/dist/twilio.min.js';

        let device = null;
        let deviceReady = false;
        let activeCall = null;
        let activeParentSid = '';
        let currentPhone = '';
        let currentSeguimientoId = 0;
        let currentInstitution = '';
        let timerInterval = null;
        let timerStartedAt = null;
        let statusInterval = null;
        let statusBusy = false;
        let conversationStarted = false;
        let muted = false;
        let lastChildCall = null;
        let pendingMetadata = null;
        let awaitingInteractionSave = false;
        let linkingMetadata = false;
        let modal = null;
        let els = null;

        window.IMPE_BROWSER_TELEPHONY_ENABLED = false;

        const sleep = function (ms) {
            return new Promise(function (resolve) {
                window.setTimeout(resolve, ms);
            });
        };

        const mostrarToast = function (mensaje, esError) {
            const contenedor = document.querySelector('.toast-container');

            if (!contenedor || !window.bootstrap) {
                return;
            }

            const toast = document.createElement('div');
            toast.className = 'toast system-toast' + (esError ? ' system-toast-error' : '');
            toast.setAttribute('role', esError ? 'alert' : 'status');
            toast.setAttribute('aria-live', esError ? 'assertive' : 'polite');
            toast.setAttribute('aria-atomic', 'true');
            toast.innerHTML =
                '<div class="toast-body">' +
                    '<i class="bi ' + (esError ? 'bi-exclamation-circle' : 'bi-check2-circle') + '"></i>' +
                    '<span></span>' +
                '</div>';
            toast.querySelector('span').textContent = mensaje;
            contenedor.appendChild(toast);

            const instancia = new bootstrap.Toast(toast, {
                autohide: true,
                delay: esError ? 4800 : 3400
            });
            toast.addEventListener('hidden.bs.toast', function () {
                toast.remove();
            });
            instancia.show();
        };

        const formatDuration = function (seconds) {
            const total = Math.max(0, Number(seconds) || 0);
            const minutes = Math.floor(total / 60);
            const secs = total % 60;
            return String(minutes).padStart(2, '0') + ':' + String(secs).padStart(2, '0');
        };

        const normalizarDestino = function (valor) {
            const original = String(valor || '').trim();
            let digits = original.replace(/\D+/g, '');

            if (digits.length === 13 && digits.startsWith('521')) {
                digits = '52' + digits.slice(3);
            }

            if (digits.length === 10) {
                return '+52' + digits;
            }

            if (digits.length === 12 && digits.startsWith('52')) {
                return '+' + digits;
            }

            if (original.startsWith('+') && digits.length >= 8 && digits.length <= 15) {
                return '+' + digits;
            }

            return '';
        };

        const fechaLocalInput = function (valor) {
            if (!valor) {
                return '';
            }

            const fecha = new Date(valor);
            if (Number.isNaN(fecha.getTime())) {
                return '';
            }

            const pad = function (numero) {
                return String(numero).padStart(2, '0');
            };

            return fecha.getFullYear() + '-' +
                pad(fecha.getMonth() + 1) + '-' +
                pad(fecha.getDate()) + 'T' +
                pad(fecha.getHours()) + ':' +
                pad(fecha.getMinutes());
        };

        const loadSdk = function () {
            if (window.Twilio && window.Twilio.Device) {
                return Promise.resolve();
            }

            return new Promise(function (resolve, reject) {
                const existente = document.querySelector('script[data-impe-twilio-sdk]');

                if (existente) {
                    existente.addEventListener('load', resolve, { once: true });
                    existente.addEventListener('error', reject, { once: true });
                    return;
                }

                const script = document.createElement('script');
                script.src = sdkUrl;
                script.async = true;
                script.setAttribute('data-impe-twilio-sdk', '');
                script.addEventListener('load', resolve, { once: true });
                script.addEventListener('error', function () {
                    reject(new Error('No fue posible cargar el componente de telefonía.'));
                }, { once: true });
                document.head.appendChild(script);
            });
        };

        const fetchToken = async function () {
            const response = await fetch(tokenUrl, {
                headers: { 'X-Requested-With': 'fetch' },
                credentials: 'same-origin',
                cache: 'no-store'
            });
            const data = await response.json();

            if (!response.ok || !data.ok || !data.token) {
                throw new Error(data.mensaje || 'No fue posible obtener el token de telefonía.');
            }

            return data;
        };

        const probe = async function () {
            try {
                await fetchToken();
                await loadSdk();
                window.IMPE_BROWSER_TELEPHONY_ENABLED = true;
            } catch (error) {
                window.IMPE_BROWSER_TELEPHONY_ENABLED = false;
                console.info('Telefonía en navegador no disponible; se conserva el canal telefónico alterno.', error);
            }
        };

        const crearModal = function () {
            let modalEl = document.getElementById('modalLlamadaVinculacion');

            if (!modalEl) {
                modalEl = document.createElement('div');
                modalEl.className = 'modal fade';
                modalEl.id = 'modalLlamadaVinculacion';
                modalEl.tabIndex = -1;
                modalEl.setAttribute('aria-hidden', 'true');
                modalEl.innerHTML =
                    '<div class="modal-dialog modal-dialog-centered linkage-call-dialog">' +
                        '<div class="modal-content linkage-call-modal">' +
                            '<div class="modal-header border-0 pb-0">' +
                                '<div>' +
                                    '<span class="linkage-call-eyebrow">LLAMADA INSTITUCIONAL</span>' +
                                    '<h5 class="modal-title" data-call-institution>Institución</h5>' +
                                '</div>' +
                                '<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>' +
                            '</div>' +
                            '<div class="modal-body">' +
                                '<div class="linkage-call-number" data-call-number>—</div>' +
                                '<div class="linkage-call-state">' +
                                    '<span data-call-status>Preparando teléfono…</span>' +
                                    '<strong data-call-timer>00:00</strong>' +
                                '</div>' +
                                '<div class="linkage-call-meters d-none" data-call-meters>' +
                                    '<div><span>Micrófono</span><div class="linkage-call-meter"><i data-call-mic></i></div></div>' +
                                    '<div><span>Audio recibido</span><div class="linkage-call-meter"><i data-call-speaker></i></div></div>' +
                                '</div>' +
                                '<div class="linkage-call-actions">' +
                                    '<button type="button" class="btn btn-system-save" data-call-start disabled>' +
                                        '<i class="bi bi-telephone"></i> Llamar' +
                                    '</button>' +
                                    '<button type="button" class="btn btn-system-light" data-call-mute disabled>' +
                                        '<i class="bi bi-mic-mute"></i> Silenciar' +
                                    '</button>' +
                                    '<button type="button" class="btn btn-outline-danger" data-call-hangup disabled>' +
                                        '<i class="bi bi-telephone-x"></i> Colgar' +
                                    '</button>' +
                                '</div>' +
                                '<div class="linkage-call-result d-none" data-call-result>' +
                                    '<i class="bi bi-check2-circle"></i>' +
                                    '<div><strong>Llamada finalizada</strong><span data-call-result-text>Registra el resultado para continuar la ruta.</span></div>' +
                                '</div>' +
                            '</div>' +
                            '<div class="modal-footer border-0 pt-0">' +
                                '<button type="button" class="btn btn-system-save d-none" data-call-register>' +
                                    '<i class="bi bi-journal-check"></i> Registrar resultado' +
                                '</button>' +
                                '<button type="button" class="btn btn-system-light" data-bs-dismiss="modal">Cerrar</button>' +
                            '</div>' +
                        '</div>' +
                    '</div>';
                document.body.appendChild(modalEl);
            }

            els = {
                modal: modalEl,
                institution: modalEl.querySelector('[data-call-institution]'),
                number: modalEl.querySelector('[data-call-number]'),
                status: modalEl.querySelector('[data-call-status]'),
                timer: modalEl.querySelector('[data-call-timer]'),
                meters: modalEl.querySelector('[data-call-meters]'),
                mic: modalEl.querySelector('[data-call-mic]'),
                speaker: modalEl.querySelector('[data-call-speaker]'),
                start: modalEl.querySelector('[data-call-start]'),
                mute: modalEl.querySelector('[data-call-mute]'),
                hangup: modalEl.querySelector('[data-call-hangup]'),
                result: modalEl.querySelector('[data-call-result]'),
                resultText: modalEl.querySelector('[data-call-result-text]'),
                register: modalEl.querySelector('[data-call-register]')
            };

            modal = bootstrap.Modal.getOrCreateInstance(modalEl, {
                backdrop: 'static',
                keyboard: false
            });

            modalEl.addEventListener('hide.bs.modal', function (event) {
                if (activeCall) {
                    event.preventDefault();
                    mostrarToast('Finaliza la llamada antes de cerrar esta ventana.', true);
                }
            });

            els.start.addEventListener('click', makeCall);
            els.mute.addEventListener('click', toggleMute);
            els.hangup.addEventListener('click', hangup);
            els.register.addEventListener('click', abrirRegistroResultado);
        };

        const startTimer = function () {
            if (timerInterval) {
                return;
            }

            timerStartedAt = Date.now();
            els.timer.textContent = '00:00';
            timerInterval = window.setInterval(function () {
                els.timer.textContent = formatDuration(
                    Math.floor((Date.now() - timerStartedAt) / 1000)
                );
            }, 500);
        };

        const stopTimer = function () {
            if (timerInterval) {
                window.clearInterval(timerInterval);
                timerInterval = null;
            }
            timerStartedAt = null;
        };

        const stopStatusPolling = function () {
            if (statusInterval) {
                window.clearInterval(statusInterval);
                statusInterval = null;
            }
            statusBusy = false;
        };

        const fetchChildCall = async function (parentSid) {
            const response = await fetch(
                estadoUrl + '?parent_sid=' + encodeURIComponent(parentSid),
                {
                    headers: { 'X-Requested-With': 'fetch' },
                    credentials: 'same-origin',
                    cache: 'no-store'
                }
            );
            const data = await response.json();

            if (!response.ok || !data.ok) {
                throw new Error(data.mensaje || 'No fue posible consultar el estado de la llamada.');
            }

            return data.call || null;
        };

        const applyChildState = function (call) {
            if (!call) {
                return;
            }

            lastChildCall = call;
            const status = String(call.status || '');

            if (status === 'queued') {
                els.status.textContent = 'Preparando llamada…';
                return;
            }

            if (status === 'ringing') {
                els.status.textContent = 'Timbrando…';
                return;
            }

            if (status === 'in-progress') {
                els.status.textContent = 'Llamada en curso';
                els.mute.disabled = false;

                if (!conversationStarted) {
                    conversationStarted = true;
                    startTimer();
                }
                return;
            }

            if (status === 'completed' && Number(call.duration) > 0) {
                els.timer.textContent = formatDuration(call.duration);
                return;
            }

            const etiquetas = {
                busy: 'Línea ocupada',
                'no-answer': 'Sin respuesta',
                failed: 'Llamada fallida',
                canceled: 'Llamada cancelada'
            };

            if (etiquetas[status]) {
                els.status.textContent = etiquetas[status];
            }
        };

        const checkStatus = async function () {
            if (!activeParentSid || statusBusy) {
                return;
            }

            statusBusy = true;
            try {
                const childCall = await fetchChildCall(activeParentSid);
                applyChildState(childCall);
            } catch (error) {
                console.warn(error);
            } finally {
                statusBusy = false;
            }
        };

        const startStatusPolling = function (parentSid) {
            stopStatusPolling();
            activeParentSid = parentSid;
            lastChildCall = null;
            checkStatus();
            statusInterval = window.setInterval(checkStatus, 850);
        };

        const ensureDevice = async function () {
            if (device && deviceReady) {
                return;
            }

            els.status.textContent = 'Solicitando micrófono…';
            els.start.disabled = true;

            await loadSdk();
            await navigator.mediaDevices.getUserMedia({ audio: true });
            const tokenData = await fetchToken();

            if (device) {
                try {
                    device.destroy();
                } catch (error) {
                    console.warn(error);
                }
            }

            deviceReady = false;
            device = new Twilio.Device(tokenData.token, { logLevel: 1 });

            device.on('registered', function () {
                deviceReady = true;
                els.status.textContent = 'Teléfono listo';
                els.start.disabled = false;
            });

            device.on('unregistered', function () {
                deviceReady = false;
                if (!activeCall) {
                    els.status.textContent = 'Teléfono desconectado';
                    els.start.disabled = true;
                }
            });

            device.on('tokenWillExpire', async function () {
                try {
                    const nuevoToken = await fetchToken();
                    device.updateToken(nuevoToken.token);
                } catch (error) {
                    console.warn(error);
                }
            });

            device.on('error', function (error) {
                mostrarToast('Telefonía: ' + error.message, true);
            });

            await device.register();
        };

        const resetForCall = function () {
            stopTimer();
            stopStatusPolling();
            activeCall = null;
            activeParentSid = '';
            lastChildCall = null;
            conversationStarted = false;
            muted = false;
            els.timer.textContent = '00:00';
            els.status.textContent = deviceReady ? 'Teléfono listo' : 'Preparando teléfono…';
            els.mic.style.width = '0%';
            els.speaker.style.width = '0%';
            els.meters.classList.add('d-none');
            els.mute.disabled = true;
            els.mute.innerHTML = '<i class="bi bi-mic-mute"></i> Silenciar';
            els.hangup.disabled = true;
            els.start.disabled = !deviceReady;
            els.result.classList.add('d-none');
            els.register.classList.add('d-none');
        };

        const resolveFinalChild = async function (parentSid) {
            let ultimo = lastChildCall;

            for (let intento = 0; intento < 8; intento++) {
                try {
                    const call = await fetchChildCall(parentSid);
                    if (call) {
                        ultimo = call;
                        applyChildState(call);

                        if (['completed', 'busy', 'no-answer', 'failed', 'canceled'].includes(String(call.status || ''))) {
                            return call;
                        }
                    }
                } catch (error) {
                    console.warn(error);
                }
                await sleep(700);
            }

            return ultimo;
        };

        const finishCall = async function (label) {
            const parentSid = activeParentSid;
            stopStatusPolling();
            stopTimer();
            activeCall = null;
            els.start.disabled = !deviceReady;
            els.mute.disabled = true;
            els.hangup.disabled = true;
            els.meters.classList.add('d-none');
            els.status.textContent = label;

            const childCall = parentSid ? await resolveFinalChild(parentSid) : lastChildCall;

            if (childCall && childCall.sid) {
                if (Number(childCall.duration) > 0) {
                    els.timer.textContent = formatDuration(childCall.duration);
                }

                pendingMetadata = {
                    seguimiento_id: currentSeguimientoId,
                    call_sid: String(childCall.sid),
                    parent_sid: String(childCall.parent_call_sid || parentSid || ''),
                    status: String(childCall.status || ''),
                    duration: Number(childCall.duration || 0),
                    start_time: childCall.start_time || null,
                    end_time: childCall.end_time || null,
                    to: childCall.to || currentPhone
                };

                const estadoTexto = Number(childCall.duration) > 0
                    ? 'Conversación: ' + formatDuration(childCall.duration) + '. La grabación se genera automáticamente.'
                    : 'La llamada terminó sin conversación. Registra el resultado para conservar el intento.';
                els.resultText.textContent = estadoTexto;
                els.result.classList.remove('d-none');
                els.register.classList.remove('d-none');
            } else {
                pendingMetadata = null;
                els.resultText.textContent = 'No se obtuvo el registro telefónico necesario para vincular esta llamada.';
                els.result.classList.remove('d-none');
            }
        };

        const bindCall = function (call) {
            activeCall = call;
            conversationStarted = false;
            els.start.disabled = true;
            els.hangup.disabled = false;
            els.mute.disabled = true;
            els.meters.classList.remove('d-none');
            els.status.textContent = 'Conectando con Twilio…';

            call.on('accept', function () {
                const parentSid = call.parameters?.CallSid ||
                    call.parameters?.CallSID ||
                    call.outboundConnectionId || '';

                els.status.textContent = 'Marcando al destino…';

                if (parentSid) {
                    startStatusPolling(parentSid);
                }
            });

            call.on('volume', function (inputVolume, outputVolume) {
                els.mic.style.width = Math.min(100, Math.round(inputVolume * 100)) + '%';
                els.speaker.style.width = Math.min(100, Math.round(outputVolume * 100)) + '%';
            });

            call.on('disconnect', function () {
                finishCall('Llamada finalizada');
            });
            call.on('cancel', function () {
                finishCall('Llamada cancelada');
            });
            call.on('reject', function () {
                finishCall('Llamada rechazada');
            });
            call.on('error', function (error) {
                mostrarToast('Error en llamada: ' + error.message, true);
            });
        };

        async function makeCall() {
            if (!device || !deviceReady || activeCall) {
                return;
            }

            try {
                resetForCall();
                els.status.textContent = 'Marcando…';
                const call = await device.connect({ params: { To: currentPhone } });
                bindCall(call);
            } catch (error) {
                els.status.textContent = 'No se pudo iniciar la llamada';
                els.start.disabled = false;
                mostrarToast(error.message || 'No fue posible iniciar la llamada.', true);
            }
        }

        function toggleMute() {
            if (!activeCall) {
                return;
            }

            muted = !muted;
            activeCall.mute(muted);
            els.mute.innerHTML = muted
                ? '<i class="bi bi-mic"></i> Activar micrófono'
                : '<i class="bi bi-mic-mute"></i> Silenciar';
        }

        function hangup() {
            if (!activeCall) {
                return;
            }

            els.hangup.disabled = true;
            els.status.textContent = 'Finalizando…';
            activeCall.disconnect();
        }

        const agregarResumenTecnico = function (formulario, metadata) {
            let aviso = formulario.querySelector('[data-twilio-call-summary]');

            if (!aviso) {
                aviso = document.createElement('div');
                aviso.className = 'col-12';
                aviso.setAttribute('data-twilio-call-summary', '');
                formulario.querySelector('.row')?.prepend(aviso);
            }

            const estado = metadata.status === 'completed'
                ? 'Completada'
                : (metadata.status === 'no-answer'
                    ? 'Sin respuesta'
                    : (metadata.status === 'busy' ? 'Ocupado' : 'Finalizada'));

            aviso.innerHTML =
                '<div class="alert alert-light border mb-1 py-2 px-3 small">' +
                    '<i class="bi bi-telephone-check me-1"></i>' +
                    '<strong>Llamada real vinculada.</strong> ' +
                    estado + ' · ' + formatDuration(metadata.duration) +
                    (metadata.duration > 0 ? ' · grabación disponible.' : '.') +
                '</div>';
        };

        function abrirRegistroResultado() {
            if (!pendingMetadata) {
                return;
            }

            const metadata = pendingMetadata;
            modal.hide();

            const boton = offcanvas.querySelector('[data-work-toggle-interaction]');
            const formulario = offcanvas.querySelector('[data-work-interaction-form]');

            if (!formulario) {
                mostrarToast('No se encontró el formulario para registrar el resultado.', true);
                return;
            }

            if (formulario.classList.contains('d-none') && boton && !boton.disabled) {
                boton.click();
            }

            window.setTimeout(function () {
                const canal = formulario.querySelector('[name="canal"]');
                const resultado = formulario.querySelector('[name="resultado"]');
                const fechaInicio = formulario.querySelector('[name="fecha_inicio"]');

                if (canal) {
                    canal.value = 'LLAMADA';
                    canal.dispatchEvent(new Event('change', { bubbles: true }));
                }

                if (fechaInicio && metadata.start_time) {
                    const valorFecha = fechaLocalInput(metadata.start_time);
                    if (valorFecha) {
                        fechaInicio.value = valorFecha;
                    }
                }

                if (resultado) {
                    let opcionVacia = resultado.querySelector('option[value=""]');
                    if (!opcionVacia) {
                        opcionVacia = document.createElement('option');
                        opcionVacia.value = '';
                        opcionVacia.textContent = 'Selecciona el resultado…';
                        resultado.prepend(opcionVacia);
                    }

                    resultado.value = metadata.status === 'no-answer'
                        ? 'SIN_RESPUESTA'
                        : '';
                    resultado.dispatchEvent(new Event('change', { bubbles: true }));
                }

                agregarResumenTecnico(formulario, metadata);
                formulario.classList.remove('d-none');
                formulario.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            }, 100);
        }

        const abrirLlamada = async function () {
            const telefonoPanel = String(
                offcanvas.querySelector('[data-work-phone]')?.textContent || ''
            ).trim();
            const telefono = normalizarDestino(telefonoPanel);
            const seguimientoId = Number(offcanvas.dataset.flowSeguimientoId || 0);

            if (!telefono) {
                mostrarToast('No hay un teléfono válido para realizar la llamada.', true);
                return;
            }

            if (seguimientoId <= 0) {
                mostrarToast('No se pudo identificar el seguimiento activo.', true);
                return;
            }

            if (!els) {
                crearModal();
            }

            currentPhone = telefono;
            currentSeguimientoId = seguimientoId;
            currentInstitution = String(
                offcanvas.querySelector('[data-work-title]')?.textContent || 'Institución'
            ).trim();
            pendingMetadata = null;
            awaitingInteractionSave = false;
            resetForCall();
            els.institution.textContent = currentInstitution || 'Institución';
            els.number.textContent = currentPhone;
            modal.show();

            try {
                await ensureDevice();
            } catch (error) {
                els.status.textContent = 'Telefonía no disponible';
                mostrarToast(error.message || 'No fue posible iniciar el teléfono del navegador.', true);
            }
        };

        const vincularMetadata = async function (attempt) {
            if (!pendingMetadata || linkingMetadata) {
                return;
            }

            linkingMetadata = true;
            const metadata = pendingMetadata;
            const formData = new FormData();
            formData.set('seguimiento_id', String(metadata.seguimiento_id));
            formData.set('call_sid', metadata.call_sid);
            formData.set('parent_sid', metadata.parent_sid || '');

            try {
                const response = await fetch(vincularUrl, {
                    method: 'POST',
                    headers: { 'X-Requested-With': 'fetch' },
                    credentials: 'same-origin',
                    body: formData
                });
                const data = await response.json();

                if (response.status === 404 && attempt < 4) {
                    linkingMetadata = false;
                    await sleep(500);
                    vincularMetadata(attempt + 1);
                    return;
                }

                if (!response.ok || !data.ok) {
                    throw new Error(data.mensaje || 'No fue posible vincular los datos técnicos de la llamada.');
                }

                pendingMetadata = null;
                awaitingInteractionSave = false;
                mostrarToast('La llamada y su duración quedaron vinculadas al expediente.', false);
                document.dispatchEvent(new CustomEvent('impe:twilio-call-linked', {
                    detail: {
                        seguimientoId: currentSeguimientoId,
                        interaccionId: Number(data.interaccion_id || 0)
                    }
                }));
            } catch (error) {
                awaitingInteractionSave = false;
                mostrarToast(error.message || 'La interacción se guardó, pero no fue posible vincular los datos técnicos.', true);
            } finally {
                linkingMetadata = false;
            }
        };

        document.addEventListener('submit', function (event) {
            const formulario = event.target;
            if (!(formulario instanceof HTMLFormElement) ||
                !formulario.matches('[data-work-interaction-form]') ||
                !pendingMetadata) {
                return;
            }

            const seguimientoForm = Number(
                formulario.querySelector('[name="seguimiento_id"]')?.value || 0
            );
            if (seguimientoForm === Number(pendingMetadata.seguimiento_id)) {
                awaitingInteractionSave = true;
            }
        }, true);

        document.addEventListener('impe:interaction-informative-saved', function (event) {
            if (!awaitingInteractionSave || !pendingMetadata) {
                return;
            }

            if (Number(event.detail?.seguimientoId || 0) === Number(pendingMetadata.seguimiento_id)) {
                window.setTimeout(function () {
                    vincularMetadata(0);
                }, 180);
            }
        });

        const toastContainer = document.querySelector('.toast-container');
        if (toastContainer && window.MutationObserver) {
            const observer = new MutationObserver(function (mutations) {
                if (!awaitingInteractionSave || !pendingMetadata) {
                    return;
                }

                const success = mutations.some(function (mutation) {
                    return Array.from(mutation.addedNodes).some(function (node) {
                        return node instanceof HTMLElement &&
                            String(node.textContent || '').includes('Interacción registrada');
                    });
                });

                if (success) {
                    window.setTimeout(function () {
                        vincularMetadata(0);
                    }, 180);
                }
            });
            observer.observe(toastContainer, { childList: true });
        }

        // Este listener se registra antes que seguimiento_canales.js. Si la
        // telefonía del navegador está disponible, consume el clic; de lo
        // contrario el manejador existente conserva el fallback tel/callto/SIP.
        document.addEventListener('click', function (event) {
            const boton = event.target.closest('[data-work-call-button]');

            if (!boton || boton.disabled || !window.IMPE_BROWSER_TELEPHONY_ENABLED) {
                return;
            }

            event.preventDefault();
            event.stopImmediatePropagation();
            abrirLlamada();
        }, true);

        offcanvas.addEventListener('hidden.bs.offcanvas', function () {
            if (activeCall) {
                activeCall.disconnect();
            }
        });

        window.addEventListener('beforeunload', function () {
            if (device) {
                try {
                    device.destroy();
                } catch (error) {
                    console.warn(error);
                }
            }
        });

        probe();
    });
})();
