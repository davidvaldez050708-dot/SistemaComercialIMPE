(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        const offcanvas = document.getElementById('offcanvasSeguimientoTrabajo');
        const rolId = Number(window.IMPE_CURRENT_ROLE_ID || 0);

        if (!offcanvas || rolId !== 4 || !window.bootstrap || typeof window.fetch !== 'function') {
            return;
        }

        const webrtcUrl = 'prueba_telefonia/api/zadarma_webrtc.php';
        const estadoUrl = 'prueba_telefonia/api/zadarma_estado_llamada.php';
        const vincularUrl = 'prueba_telefonia/api/vincular_interaccion_zadarma.php';
        const sdkLibUrl = 'https://my.zadarma.com/webphoneWebRTCWidget/v8/js/loader-phone-lib.js?v=23';
        const sdkFnUrl = 'https://my.zadarma.com/webphoneWebRTCWidget/v8/js/loader-phone-fn.js?v=23';

        let extension = '';
        let sipLogin = '';
        let webRtcKey = '';
        let widgetReady = false;
        let widgetInitialized = false;
        let activeCall = false;
        let hangingUp = false;
        let finishing = false;
        let currentPhone = '';
        let currentSeguimientoId = 0;
        let currentInstitution = '';
        let currentPbxCallId = '';
        let callRequestedAt = 0;
        let lastCallState = null;
        let statusInterval = null;
        let statusBusy = false;
        let timerInterval = null;
        let timerStartedAt = null;
        let conversationStarted = false;
        let muted = false;
        let nativeStateInterval = null;
        const microphoneTracks = new Set();
        let pendingMetadata = null;
        let awaitingInteractionSave = false;
        let linkingMetadata = false;
        let modal = null;
        let els = null;

        window.IMPE_ZADARMA_TELEPHONY_READY = false;

        const capturarMicrofonoZadarma = function () {
            const mediaDevices = navigator.mediaDevices;
            if (
                !mediaDevices ||
                typeof mediaDevices.getUserMedia !== 'function' ||
                mediaDevices.getUserMedia.__impeZadarmaWrapped
            ) {
                return;
            }

            const original = mediaDevices.getUserMedia.bind(mediaDevices);
            const wrapped = function (constraints) {
                return original(constraints).then(function (stream) {
                    if (constraints && constraints.audio) {
                        stream.getAudioTracks().forEach(function (track) {
                            microphoneTracks.add(track);
                            track.enabled = !muted;
                            track.addEventListener('ended', function () {
                                microphoneTracks.delete(track);
                            }, { once: true });
                        });
                    }
                    return stream;
                });
            };

            wrapped.__impeZadarmaWrapped = true;
            mediaDevices.getUserMedia = wrapped;
        };

        capturarMicrofonoZadarma();

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

            const texto = String(valor).trim();
            const coincidencia = texto.match(/^(\d{4}-\d{2}-\d{2})[ T](\d{2}:\d{2})/);
            if (coincidencia) {
                return coincidencia[1] + 'T' + coincidencia[2];
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

        const waitFor = function (check, timeoutMs, stepMs) {
            const timeout = Number(timeoutMs) || 12000;
            const step = Number(stepMs) || 100;

            if (check()) {
                return Promise.resolve(true);
            }

            return new Promise(function (resolve) {
                const startedAt = Date.now();
                const interval = window.setInterval(function () {
                    if (check()) {
                        window.clearInterval(interval);
                        resolve(true);
                        return;
                    }

                    if (Date.now() - startedAt >= timeout) {
                        window.clearInterval(interval);
                        resolve(false);
                    }
                }, step);
            });
        };

        const loadScript = function (src, marker) {
            const selector = 'script[data-impe-zadarma-' + marker + ']';
            const existente = document.querySelector(selector);

            if (existente) {
                if (existente.dataset.loaded === '1') {
                    return Promise.resolve();
                }

                return new Promise(function (resolve, reject) {
                    existente.addEventListener('load', resolve, { once: true });
                    existente.addEventListener('error', reject, { once: true });
                });
            }

            return new Promise(function (resolve, reject) {
                const script = document.createElement('script');
                script.src = src;
                script.async = true;
                script.setAttribute('data-impe-zadarma-' + marker, '');
                script.addEventListener('load', function () {
                    script.dataset.loaded = '1';
                    resolve();
                }, { once: true });
                script.addEventListener('error', function () {
                    reject(new Error('No fue posible cargar el teléfono WebRTC de Zadarma.'));
                }, { once: true });
                document.head.appendChild(script);
            });
        };

        const fetchWebRtcSession = async function () {
            const response = await fetch(webrtcUrl, {
                method: 'GET',
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'fetch'
                },
                credentials: 'same-origin',
                cache: 'no-store'
            });
            const data = await response.json();

            if (!response.ok || !data.ok || !data.webrtc_key || !data.sip_login) {
                throw new Error(data.mensaje || 'No fue posible preparar Zadarma WebRTC.');
            }

            extension = String(data.extension || '').trim();
            sipLogin = String(data.sip_login || '').trim();
            webRtcKey = String(data.webrtc_key || '').trim();
        };

        const primeAudio = function () {
            try {
                const AudioContextCtor = window.AudioContext || window.webkitAudioContext;
                if (!AudioContextCtor) {
                    return;
                }

                if (!window.IMPE_ZADARMA_AUDIO_CONTEXT) {
                    window.IMPE_ZADARMA_AUDIO_CONTEXT = new AudioContextCtor();
                }

                const contexto = window.IMPE_ZADARMA_AUDIO_CONTEXT;
                if (contexto && contexto.state === 'suspended') {
                    contexto.resume().catch(function () {});
                }
            } catch (error) {
                console.debug('No fue necesario preparar el audio del navegador.', error);
            }
        };

        const ensureWidget = async function () {
            if (widgetReady && window.zdrmWebPhone && typeof window.zdrmWebPhone.regToCall === 'function') {
                return;
            }

            if (window.location.protocol !== 'https:') {
                throw new Error('Zadarma WebRTC requiere abrir el sistema mediante HTTPS.');
            }

            if (!webRtcKey || !sipLogin) {
                await fetchWebRtcSession();
            }

            await loadScript(sdkLibUrl, 'lib');
            await loadScript(sdkFnUrl, 'fn');

            const loadersReady = await waitFor(function () {
                return typeof window.zadarmaWidgetFn === 'function' &&
                    typeof window.zdrmWebrtcPhoneInterface === 'function';
            }, 15000, 100);

            if (!loadersReady) {
                throw new Error('El componente WebRTC de Zadarma no terminó de cargar.');
            }

            if (!widgetInitialized) {
                window.zadarmaWidgetFn(
                    webRtcKey,
                    sipLogin,
                    'rounded',
                    'es',
                    true,
                    "{right:'18px',bottom:'18px'}"
                );
                widgetInitialized = true;
            }

            const apiReady = await waitFor(function () {
                return window.zdrmWebPhone &&
                    typeof window.zdrmWebPhone.regToCall === 'function';
            }, 15000, 100);

            if (!apiReady) {
                throw new Error('Zadarma no publicó el control de llamadas WebRTC para este dominio.');
            }

            widgetReady = true;
            window.IMPE_ZADARMA_TELEPHONY_READY = true;
            window.IMPE_BROWSER_TELEPHONY_ENABLED = true;
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
                                '<div class="linkage-call-origin" data-call-origin>' +
                                    '<i class="bi bi-building-check" aria-hidden="true"></i>' +
                                    '<span>Desde: <strong data-call-extension>Extensión —</strong> · Zadarma</span>' +
                                '</div>' +
                                '<div class="linkage-call-state">' +
                                    '<span data-call-status>Preparando teléfono…</span>' +
                                    '<strong data-call-timer>00:00</strong>' +
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

            modalEl.dataset.callProvider = 'ZADARMA';

            els = {
                modal: modalEl,
                institution: modalEl.querySelector('[data-call-institution]'),
                number: modalEl.querySelector('[data-call-number]'),
                origin: modalEl.querySelector('[data-call-origin]'),
                extension: modalEl.querySelector('[data-call-extension]'),
                status: modalEl.querySelector('[data-call-status]'),
                timer: modalEl.querySelector('[data-call-timer]'),
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

            if (modalEl.dataset.zadarmaEventsReady !== '1') {
                modalEl.dataset.zadarmaEventsReady = '1';

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
            }
        };

        const startTimer = function (segundosIniciales) {
            if (timerInterval) {
                return;
            }

            const iniciales = Math.max(0, Number(segundosIniciales) || 0);
            timerStartedAt = Date.now() - (iniciales * 1000);
            els.timer.textContent = formatDuration(iniciales);
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

        const setMuted = function (value) {
            muted = Boolean(value);

            microphoneTracks.forEach(function (track) {
                if (track && track.readyState === 'live') {
                    track.enabled = !muted;
                }
            });

            if (els?.mute) {
                els.mute.innerHTML = muted
                    ? '<i class="bi bi-mic"></i> Activar micrófono'
                    : '<i class="bi bi-mic-mute"></i> Silenciar';
            }
        };

        function toggleMute() {
            if (!activeCall || !conversationStarted) {
                return;
            }
            setMuted(!muted);
        }

        const marcarConversacionIniciada = function (segundosIniciales) {
            if (!activeCall) {
                return;
            }

            if (!conversationStarted) {
                conversationStarted = true;
                els.status.textContent = 'Llamada en curso';
                els.mute.disabled = false;
                startTimer(segundosIniciales || 0);
                return;
            }

            if (!timerInterval) {
                startTimer(segundosIniciales || 0);
            }
        };

        const segundosWidgetNativo = function () {
            const candidatos = document.querySelectorAll(
                '[id*="zadarma" i], [class*="zadarma" i], ' +
                '[id*="zdrm" i], [class*="zdrm" i], ' +
                '[id*="webphone" i], [class*="webphone" i]'
            );
            let maximo = 0;

            candidatos.forEach(function (elemento) {
                if (
                    !(elemento instanceof HTMLElement) ||
                    elemento.closest('#modalLlamadaVinculacion')
                ) {
                    return;
                }

                const texto = String(elemento.textContent || '');
                const expresion = /(?:^|\D)(\d{1,2}):([0-5]\d)(?:\D|$)/g;
                let match;

                while ((match = expresion.exec(texto)) !== null) {
                    const segundos = (Number(match[1]) * 60) + Number(match[2]);
                    maximo = Math.max(maximo, segundos);
                }
            });

            return maximo;
        };

        const widgetNativoIndicaConversacion = function () {
            const candidatos = document.querySelectorAll(
                '[id*="zadarma" i], [class*="zadarma" i], ' +
                '[id*="zdrm" i], [class*="zdrm" i], ' +
                '[id*="webphone" i], [class*="webphone" i]'
            );
            let texto = '';

            candidatos.forEach(function (elemento) {
                if (
                    elemento instanceof HTMLElement &&
                    !elemento.closest('#modalLlamadaVinculacion')
                ) {
                    texto += ' ' + String(elemento.textContent || '');
                }
            });

            texto = texto
                .normalize('NFD')
                .replace(/[\u0300-\u036f]/g, '')
                .toLowerCase();

            return /(llamada en curso|en llamada|conectad[oa]|hablando|conversation|connected|talking)/.test(texto);
        };

        const revisarEstadoWidgetNativo = function () {
            if (!activeCall || conversationStarted) {
                return;
            }

            const segundos = segundosWidgetNativo();
            if (segundos > 0 || widgetNativoIndicaConversacion()) {
                marcarConversacionIniciada(segundos);
            }
        };

        const iniciarMonitorWidgetNativo = function () {
            if (nativeStateInterval) {
                window.clearInterval(nativeStateInterval);
            }

            revisarEstadoWidgetNativo();
            nativeStateInterval = window.setInterval(revisarEstadoWidgetNativo, 300);
        };

        const detenerMonitorWidgetNativo = function () {
            if (nativeStateInterval) {
                window.clearInterval(nativeStateInterval);
                nativeStateInterval = null;
            }
        };

        const resetForCall = function () {
            stopTimer();
            stopStatusPolling();
            detenerMonitorWidgetNativo();
            activeCall = false;
            hangingUp = false;
            finishing = false;
            conversationStarted = false;
            setMuted(false);
            currentPbxCallId = '';
            callRequestedAt = 0;
            lastCallState = null;
            els.timer.textContent = '00:00';
            els.status.textContent = widgetReady ? 'Teléfono listo' : 'Preparando teléfono…';
            els.mute.disabled = true;
            els.hangup.disabled = true;
            els.start.disabled = !widgetReady;
            els.result.classList.add('d-none');
            els.register.classList.add('d-none');
        };

        const fetchCallState = async function (forzarFinal) {
            if (!currentPhone || !callRequestedAt) {
                return null;
            }

            const params = new URLSearchParams({
                destination: currentPhone,
                since: String(callRequestedAt)
            });

            if (forzarFinal) {
                params.set('final', '1');
            }
            const response = await fetch(estadoUrl + '?' + params.toString(), {
                method: 'GET',
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'fetch'
                },
                credentials: 'same-origin',
                cache: 'no-store'
            });
            const data = await response.json();

            if (!response.ok || !data.ok) {
                throw new Error(data.mensaje || 'No fue posible consultar el estado de la llamada Zadarma.');
            }

            return data.call || null;
        };

        const etiquetaEstado = function (status) {
            const etiquetas = {
                ringing: 'Timbrando…',
                'in-progress': 'Llamada en curso',
                completed: 'Llamada finalizada',
                busy: 'Línea ocupada',
                'no-answer': 'Sin respuesta',
                failed: 'Llamada fallida',
                canceled: 'Llamada cancelada'
            };
            return etiquetas[status] || 'Procesando llamada…';
        };

        const estadoEsFinal = function (status) {
            return ['completed', 'busy', 'no-answer', 'failed', 'canceled'].includes(String(status || ''));
        };

        const finishCall = async function (call, label) {
            if (finishing) {
                return;
            }

            finishing = true;

            const duracionLocal = timerStartedAt
                ? Math.max(0, Math.floor((Date.now() - timerStartedAt) / 1000))
                : 0;

            stopStatusPolling();
            detenerMonitorWidgetNativo();
            stopTimer();
            activeCall = false;
            hangingUp = false;

            if (call) {
                lastCallState = call;
                currentPbxCallId = String(call.pbx_call_id || currentPbxCallId || '');
            }

            const finalCall = call || lastCallState || {};
            let finalStatus = String(finalCall.status || 'failed');
            const duracionProveedor = Math.max(0, Number(finalCall.duration || 0));
            const duration = Math.max(duracionProveedor, duracionLocal);

            if (
                duration > 0 &&
                (finalStatus === 'in-progress' || finalStatus === 'ringing' || finalStatus === '')
            ) {
                finalStatus = 'completed';
            }

            const callIdWithRec = String(finalCall.call_id_with_rec || '').trim();
            const marcadaComoGrabada =
                finalCall.is_recorded === true ||
                String(finalCall.is_recorded || '') === '1';
            const grabacionLista =
                finalCall.record_ready === true ||
                String(finalCall.record_ready || '') === '1';

            let recordingState = 'none';
            if (grabacionLista) {
                recordingState = 'available';
            } else if (
                finalStatus === 'completed' &&
                duration > 0 &&
                (marcadaComoGrabada || callIdWithRec !== '' || duracionLocal > 0)
            ) {
                recordingState = 'processing';
            }

            els.status.textContent = label || etiquetaEstado(finalStatus);
            els.start.disabled = !widgetReady;
            els.mute.disabled = true;
            els.hangup.disabled = true;
            setMuted(false);

            if (duration > 0) {
                els.timer.textContent = formatDuration(duration);
            }

            pendingMetadata = {
                seguimiento_id: currentSeguimientoId,
                pbx_call_id: String(finalCall.pbx_call_id || currentPbxCallId || ''),
                status: finalStatus,
                duration: duration,
                start_time: finalCall.start_time || null,
                end_time: finalCall.end_time || null,
                requested_at: callRequestedAt,
                to: finalCall.destination || currentPhone,
                is_recorded: marcadaComoGrabada,
                record_ready: grabacionLista,
                recording_state: recordingState,
                call_id_with_rec: callIdWithRec
            };

            let estadoTexto = 'La llamada terminó. Registra el resultado para conservar la gestión.';
            if (duration > 0) {
                estadoTexto = 'Conversación: ' + formatDuration(duration) +
                    (recordingState === 'available'
                        ? '. La grabación ya fue confirmada por Zadarma.'
                        : '. La grabación se está procesando en Zadarma.');
            } else if (finalStatus === 'busy') {
                estadoTexto = 'La línea estaba ocupada. Registra el resultado para conservar el intento.';
            } else if (finalStatus === 'no-answer') {
                estadoTexto = 'No hubo respuesta. Registra el resultado para conservar el intento.';
            }

            els.resultText.textContent = estadoTexto;
            els.result.classList.remove('d-none');
            els.register.classList.remove('d-none');
            finishing = false;
        };

        const applyCallState = function (call) {
            if (!call || !activeCall) {
                return;
            }

            lastCallState = call;
            currentPbxCallId = String(call.pbx_call_id || currentPbxCallId || '');
            const status = String(call.status || '');
            els.status.textContent = etiquetaEstado(status);

            if (status === 'in-progress') {
                marcarConversacionIniciada(0);
            }

            if (estadoEsFinal(status)) {
                void finishCall(call, etiquetaEstado(status));
            }
        };

        const checkStatus = async function () {
            if (!activeCall || statusBusy) {
                return;
            }

            statusBusy = true;
            try {
                const call = await fetchCallState();
                if (call) {
                    applyCallState(call);
                }
            } catch (error) {
                console.warn(error);
            } finally {
                statusBusy = false;
            }
        };

        const startStatusPolling = function () {
            stopStatusPolling();
            checkStatus();
            statusInterval = window.setInterval(checkStatus, 800);
        };

        async function makeCall() {
            if (!widgetReady || activeCall || !window.zdrmWebPhone || typeof window.zdrmWebPhone.regToCall !== 'function') {
                return;
            }

            try {
                primeAudio();
                pendingMetadata = null;
                resetForCall();
                activeCall = true;
                callRequestedAt = Math.floor(Date.now() / 1000);
                els.status.textContent = 'Marcando…';
                els.start.disabled = true;
                els.mute.disabled = true;
                els.hangup.disabled = false;
                iniciarMonitorWidgetNativo();

                const destino = currentPhone.replace(/^\+/, '');
                const resultado = window.zdrmWebPhone.regToCall(destino);

                if (typeof resultado === 'string' && resultado.trim() !== '') {
                    throw new Error(resultado.trim());
                }

                startStatusPolling();
            } catch (error) {
                activeCall = false;
                els.hangup.disabled = true;
                els.start.disabled = false;
                els.status.textContent = 'No se pudo iniciar la llamada';
                mostrarToast(error.message || 'No fue posible iniciar la llamada con Zadarma.', true);
            }
        }

        function hangup() {
            if (!activeCall || hangingUp) {
                return;
            }

            hangingUp = true;
            els.status.textContent = 'Finalizando…';

            try {
                if (window.zdrmWebPhone && typeof window.zdrmWebPhone.regToCancel === 'function') {
                    window.zdrmWebPhone.regToCancel();
                } else if (window.zdrmWebPhone && typeof window.zdrmWebPhone.finishCall === 'function') {
                    window.zdrmWebPhone.finishCall();
                }
            } catch (error) {
                console.warn(error);
            }

            window.setTimeout(async function () {
                if (!activeCall) {
                    return;
                }

                let finalCall = null;

                for (let intento = 0; intento < 5; intento += 1) {
                    try {
                        const call = await fetchCallState(true);
                        if (call) {
                            finalCall = call;
                            if (estadoEsFinal(call.status)) {
                                break;
                            }
                        }
                    } catch (error) {
                        console.warn(error);
                    }

                    await sleep(900);
                }

                await finishCall(
                    finalCall || lastCallState,
                    finalCall ? etiquetaEstado(finalCall.status) : 'Llamada finalizada'
                );
            }, 1200);
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

            const recordingState = String(metadata.recording_state || 'none');
            const textoGrabacion = recordingState === 'available'
                ? ' · grabación disponible.'
                : (recordingState === 'processing'
                    ? ' · grabación procesándose.'
                    : '.');

            aviso.dataset.callDurationSeconds = String(
                Math.max(0, Number(metadata.duration || 0))
            );
            aviso.dataset.callRecordingState = recordingState;
            aviso.innerHTML =
                '<div class="alert alert-light border mb-1 py-2 px-3 small">' +
                    '<i class="bi bi-telephone-check me-1"></i>' +
                    '<strong>Llamada real vinculada.</strong> ' +
                    estado + ' · ' + formatDuration(metadata.duration) +
                    textoGrabacion +
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

                    if (metadata.status === 'no-answer') {
                        resultado.value = 'SIN_RESPUESTA';
                    } else if (metadata.status === 'busy') {
                        resultado.value = 'OCUPADO';
                    } else {
                        resultado.value = '';
                    }
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
            els.extension.textContent = extension ? 'Extensión ' + extension : 'Extensión —';
            modal.show();

            try {
                await ensureWidget();
                els.status.textContent = 'Teléfono listo';
                els.start.disabled = false;
            } catch (error) {
                els.status.textContent = 'Telefonía no disponible';
                mostrarToast(error.message || 'No fue posible iniciar Zadarma WebRTC.', true);
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
            formData.set('pbx_call_id', String(metadata.pbx_call_id || ''));
            formData.set('destination', String(metadata.to || currentPhone || ''));
            formData.set('since', String(metadata.requested_at || callRequestedAt || 0));
            formData.set('duration_client', String(Math.max(0, Number(metadata.duration || 0))));

            try {
                const response = await fetch(vincularUrl, {
                    method: 'POST',
                    headers: { 'X-Requested-With': 'fetch' },
                    credentials: 'same-origin',
                    body: formData
                });
                const data = await response.json();

                if ([404, 409].includes(response.status) && attempt < 12) {
                    linkingMetadata = false;
                    await sleep(750);
                    void vincularMetadata(attempt + 1);
                    return;
                }

                if (!response.ok || !data.ok) {
                    throw new Error(data.mensaje || 'No fue posible vincular los datos técnicos de la llamada Zadarma.');
                }

                pendingMetadata = null;
                awaitingInteractionSave = false;
                mostrarToast('La llamada Zadarma y su duración quedaron vinculadas al expediente.', false);

                const detail = {
                    seguimientoId: currentSeguimientoId,
                    interaccionId: Number(data.interaccion_id || 0),
                    provider: 'ZADARMA'
                };
                document.dispatchEvent(new CustomEvent('impe:telephony-call-linked', { detail: detail }));
                document.dispatchEvent(new CustomEvent('impe:twilio-call-linked', { detail: detail }));
            } catch (error) {
                awaitingInteractionSave = false;
                mostrarToast(error.message || 'La interacción se guardó, pero no fue posible vincular los datos técnicos de Zadarma.', true);
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
                    void vincularMetadata(0);
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
                        void vincularMetadata(0);
                    }, 180);
                }
            });
            observer.observe(toastContainer, { childList: true });
        }

        document.addEventListener('click', function (event) {
            const boton = event.target.closest('[data-work-call-button]');

            if (!boton || boton.disabled || !window.IMPE_ZADARMA_TELEPHONY_READY) {
                return;
            }

            event.preventDefault();
            event.stopImmediatePropagation();
            primeAudio();
            void abrirLlamada();
        }, true);

        const probe = async function () {
            if (window.location.protocol !== 'https:') {
                return;
            }

            try {
                await ensureWidget();
            } catch (error) {
                widgetReady = false;
                window.IMPE_ZADARMA_TELEPHONY_READY = false;
                console.info('Zadarma WebRTC no está disponible; se conserva la telefonía alterna.', error);
            }
        };

        void probe();
    });
})();
