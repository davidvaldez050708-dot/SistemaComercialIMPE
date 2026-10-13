(function () {
    'use strict';

    const config = window.IMPE_TELEPHONY_HOST || {};
    const userId = Number(config.userId || 0);
    const extension = String(config.extension || '').trim();
    const permiteSalientes =
        Boolean(config.permiteSalientes);
    const permiteEntrantes =
        Boolean(config.permiteEntrantes);
    const permiteTransferir =
        Boolean(config.permiteTransferir);
    const visualizacionHost = new URLSearchParams(window.location.search)
        .get('display');
    const modoPestana = visualizacionHost === 'tab';
    const modoEmbebido = visualizacionHost === 'embed';
    let nativeUiVisible = false;

    if (userId <= 0 || extension === '') {
        return;
    }

    const concealOwnWindow = function () {
        if (modoPestana || modoEmbebido || nativeUiVisible) {
            return;
        }

        const screenLeft =
            Number(window.screen?.availLeft || 0);
        const screenTop =
            Number(window.screen?.availTop || 0);
        const screenWidth =
            Number(
                window.screen?.availWidth ||
                window.screen?.width ||
                0
            );
        const screenHeight =
            Number(
                window.screen?.availHeight ||
                window.screen?.height ||
                0
            );

        try {
            window.resizeTo(120, 80);
        } catch (error) {
            // Chrome puede imponer dimensiones mínimas.
        }

        try {
            window.moveTo(
                screenLeft + screenWidth + 80,
                screenTop + screenHeight + 80
            );
        } catch (error) {
            // Chrome puede limitar ventanas fuera de pantalla.
        }

        try {
            window.blur();

            if (
                window.opener &&
                !window.opener.closed
            ) {
                window.opener.focus();
            }
        } catch (error) {
            // El navegador decide finalmente el foco.
        }
    };

    concealOwnWindow();

    [80, 250, 700].forEach(function (delay) {
        window.setTimeout(
            concealOwnWindow,
            delay
        );
    });

    const stateKey = 'impe:telephony:state:' + userId;
    const commandKey = 'impe:telephony:command:' + userId;
    const channelName = 'impe-telephony-' + userId;

    const sdkLibUrl =
        'https://my.zadarma.com/webphoneWebRTCWidget/v8/js/loader-phone-lib.js?v=23';
    const sdkFnUrl =
        'https://my.zadarma.com/webphoneWebRTCWidget/v8/js/loader-phone-fn.js?v=23';

    const channel = typeof BroadcastChannel === 'function'
        ? new BroadcastChannel(channelName)
        : null;

    // El SDK conserva sus propios botones, posiciones y estilos.
    // Alterar nodos internos rompía Contestar y dejaba un panel blanco.
    const ocultarInterfazNativa = function () {
        if (!nativeUiVisible) {
            document.body.classList.remove('telephony-host-incoming');
        }
    };
    const mostrarInterfazNativa = function () {
        nativeUiVisible = true;
        document.body.classList.add('telephony-host-incoming');
    };

    const mostrarHostEntrante = function () {
        mostrarInterfazNativa();
        const screenLeft =
            Number(window.screen?.availLeft || 0);
        const screenTop =
            Number(window.screen?.availTop || 0);
        const screenWidth =
            Number(
                window.screen?.availWidth ||
                window.screen?.width ||
                1280
            );

        if (!modoPestana && !modoEmbebido) {
            try {
                window.resizeTo(420, 620);
            } catch (error) {
                // El navegador puede limitar dimensiones.
            }

            try {
                window.moveTo(
                    screenLeft +
                        Math.max(20, screenWidth - 450),
                    screenTop + 40
                );
            } catch (error) {
                // El navegador decide la posición final.
            }
        }

        if (!modoEmbebido) {
            try {
                window.focus();
            } catch (error) {
                // El foco puede quedar en la ventana principal.
            }
        }
    };

    const ocultarHostEntrante = function () {
        nativeUiVisible = false;
        document.body.classList.remove('telephony-host-incoming');
        ocultarInterfazNativa();

        window.setTimeout(
            concealOwnWindow,
            80
        );
    };

    // Ningún MutationObserver recoloca elementos del proveedor.

    const titleEl = document.querySelector('[data-host-title]');
    const statusEl = document.querySelector('[data-host-status]');
    const liveEl = document.querySelector('[data-host-live]');
    const timerEl = document.querySelector('[data-host-timer]');

    let widgetReady = false;
    let incomingBridge = null;
    let widgetInitialized = false;
    let sipLogin = '';
    let webRtcKey = '';
    let pollInterval = null;
    let pollBusy = false;
    let incomingPollInterval = null;
    let incomingPollBusy = false;
    let incomingFinishedTimer = null;
    let analystSdkObserver = null;
    let hangingUp = false;
    let muted = false;
    let answeredAtMs = 0;
    let lastCall = null;
    let lastCommandId = '';
    const microphoneTracks = new Set();

    const emptyState = function () {
        return {
            version: 1,
            userId: userId,
            extension: extension,
            provider: 'ZADARMA',
            hostReady: false,
            phase: 'idle',
            active: false,
            muted: false,
            direction: '',
            calledDid: '',
            destination: '',
            institution: '',
            status: 'idle',
            duration: 0,
            requestedAt: 0,
            afterEventId: 0,
            checkpointReady: false,
            answeredAtMs: 0,
            pbxCallId: '',
            callToken: '',
            context: null,
            finalMetadata: null,
            message: '',
            updatedAt: Date.now()
        };
    };

    const readState = function () {
        try {
            const raw = localStorage.getItem(stateKey);
            const parsed = raw ? JSON.parse(raw) : null;

            if (parsed && Number(parsed.userId || 0) === userId) {
                return Object.assign(emptyState(), parsed);
            }
        } catch (error) {
            // Se reconstruye un estado limpio.
        }

        return emptyState();
    };

    let state = readState();
    const manualCall = call =>
        ['TELEPHONY_MANUAL', 'MARKETING_CALLBACK', 'DIALER'].includes(
            String(call?.context?.type || '').toUpperCase()
        );
    // Marketing no tiene resultado comercial pendiente tras Colgar.
    // No restaurar una llamada manual finalizada de una sesión anterior.
    if (!state.active && state.phase === 'finished' && manualCall(state)) {
        state = emptyState();
    }

    /*
     * Si esta página se cargó de nuevo, el PeerConnection anterior ya no existe.
     * Se elimina cualquier bandera activa antigua para evitar una llamada fantasma.
     */
    if (state.active) {
        state = Object.assign(emptyState(), {
            phase: 'interrupted',
            status: 'interrupted',
            destination: state.destination || '',
            institution: state.institution || '',
            context: state.context || null,
            message:
                'La ventana telefónica se reinició. Verifica la llamada antes de volver a marcar.'
        });
    }

    const formatDuration = function (seconds) {
        const total = Math.max(0, Number(seconds) || 0);
        const minutes = Math.floor(total / 60);
        const secs = total % 60;

        return String(minutes).padStart(2, '0') +
            ':' +
            String(secs).padStart(2, '0');
    };

    const currentDuration = function () {
        // El timbrado no es conversación. Algunos eventos de la PBX
        // reutilizan el duration de la llamada previa durante el ring.
        if (state.direction === 'incoming' &&
            state.phase === 'incoming-ringing') return 0;
        let duration = Math.max(0, Number(state.duration || 0));

        if (state.active && answeredAtMs > 0) {
            duration = Math.max(
                duration,
                Math.floor((Date.now() - answeredAtMs) / 1000)
            );
        }

        return duration;
    };

    const render = function () {
        if (timerEl) {
            timerEl.textContent = formatDuration(currentDuration());
        }

        if (liveEl) {
            liveEl.hidden = !state.active;
        }

        if (titleEl) {
            titleEl.textContent = state.active
                ? (
                    state.direction === 'incoming'
                        ? 'Entrante · ' +
                            (state.destination || 'Número desconocido')
                        : (
                            state.institution ||
                            state.destination ||
                            'Llamada en curso'
                        )
                )
                : 'Extensión ' + extension + ' disponible';
        }

        if (statusEl) {
            const labels = {
                dialing: 'Marcando…',
                ringing: 'Timbrando…',
                'incoming-ringing': 'Llamada entrante…',
                'in-progress': state.direction === 'incoming'
                    ? 'Conversación entrante'
                    : 'Llamada en curso',
                transferred: 'Llamada transferida',
                finishing: 'Finalizando…'
            };

            if (state.active) {
                statusEl.textContent =
                    labels[state.status] || 'Llamada activa';
            } else if (state.phase === 'finished') {
                statusEl.textContent =
                    'Llamada finalizada. Continúa el registro desde el sistema.';
            } else if (state.message) {
                statusEl.textContent = state.message;
            } else {
                statusEl.textContent =
                    'Mantén esta ventana abierta mientras utilices llamadas.';
            }
        }
    };

    const publish = function (patch) {
        state = Object.assign({}, state, patch || {}, {
            userId: userId,
            extension: extension,
            provider: 'ZADARMA',
            // Un webhook PBX por sí solo no confirma WebRTC.
            hostReady: widgetReady,
            duration: currentDuration(),
            updatedAt: Date.now()
        });

        try {
            localStorage.setItem(stateKey, JSON.stringify(state));
        } catch (error) {
            // BroadcastChannel puede seguir sincronizando la sesión.
        }

        if (channel) {
            channel.postMessage({
                type: 'STATE',
                state: state
            });
        }

        render();
        return state;
    };

    const sleep = function (ms) {
        return new Promise(function (resolve) {
            setTimeout(resolve, ms);
        });
    };

    const waitFor = function (check, timeoutMs, stepMs) {
        const timeout = Number(timeoutMs) || 15000;
        const step = Number(stepMs) || 100;

        if (check()) {
            return Promise.resolve(true);
        }

        return new Promise(function (resolve) {
            const startedAt = Date.now();
            const interval = setInterval(function () {
                if (check()) {
                    clearInterval(interval);
                    resolve(true);
                    return;
                }

                if (Date.now() - startedAt >= timeout) {
                    clearInterval(interval);
                    resolve(false);
                }
            }, step);
        });
    };

    const loadScript = function (src, marker) {
        const selector =
            'script[data-impe-telephony-host-' + marker + ']';
        const existing = document.querySelector(selector);

        if (existing) {
            if (existing.dataset.loaded === '1') {
                return Promise.resolve();
            }

            return new Promise(function (resolve, reject) {
                existing.addEventListener('load', resolve, { once: true });
                existing.addEventListener('error', reject, { once: true });
            });
        }

        return new Promise(function (resolve, reject) {
            const script = document.createElement('script');
            script.src = src;
            script.async = true;
            script.setAttribute(
                'data-impe-telephony-host-' + marker,
                ''
            );
            script.addEventListener('load', function () {
                script.dataset.loaded = '1';
                resolve();
            }, { once: true });
            script.addEventListener('error', function () {
                reject(new Error('No fue posible cargar el teléfono WebRTC.'));
            }, { once: true });
            document.head.appendChild(script);
        });
    };

    const captureMicrophone = function () {
        const mediaDevices = navigator.mediaDevices;

        if (
            !mediaDevices ||
            typeof mediaDevices.getUserMedia !== 'function' ||
            mediaDevices.getUserMedia.__impePersistentWrapped
        ) {
            return;
        }

        try {
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

            wrapped.__impePersistentWrapped = true;
            mediaDevices.getUserMedia = wrapped;
        } catch (error) {
            console.debug('No fue necesario envolver el micrófono.', error);
        }
    };

    const setMuted = function (value) {
        const nextMuted = Boolean(value);
        if (state.direction === 'incoming') {
            if (!state.active || state.phase !== 'in-progress' ||
                !incomingBridge?.mute(nextMuted)) {
                console.warn('No se pudo cambiar el micrófono de la llamada entrante.');
                return;
            }
        }
        // Mantener el comportamiento existente de pistas para las salientes
        // y garantizar el mismo estado visual tras actualizar el micrófono.
        muted = nextMuted;
        microphoneTracks.forEach(function (track) {
            if (track && track.readyState === 'live') {
                track.enabled = !muted;
            }
        });
        publish({ muted: muted });
    };

    const fetchWebRtcSession = async function () {
        const response = await fetch(String(config.webrtcUrl || ''), {
            method: 'GET',
            credentials: 'same-origin',
            cache: 'no-store',
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'fetch'
            }
        });
        const data = await response.json();

        if (
            !response.ok ||
            !data.ok ||
            !data.webrtc_key ||
            !data.sip_login
        ) {
            throw new Error(
                data.mensaje ||
                'No fue posible preparar la telefonía WebRTC.'
            );
        }

        sipLogin = String(data.sip_login || '').trim();
        webRtcKey = String(data.webrtc_key || '').trim();
    };

    // Únicamente las llamadas detectadas por el SDK SIN identificador PBX
    // usan esta limpieza local; jamás se crea una llamada de historial falsa.
    const isSdkOnlyIncoming = function () {
        return Boolean(config.analystSdkIncoming &&
            state.direction === 'incoming' &&
            state.context?.type === 'SDK_INCOMING' &&
            !String(state.pbxCallId || '').trim());
    };

    // Cola secuencial: el FIN nunca se guarda antes de la entrada.
    let sdkJournalQueue = Promise.resolve();
    const reportSdkEvent = function (evento, current) {
        if (!config.analystSdkIncoming ||
            !config.analystSdkJournalUrl || !config.analystSdkJournalCsrf ||
            current?.context?.type !== 'SDK_INCOMING') return;
        const data = new URLSearchParams({
            csrf_token: String(config.analystSdkJournalCsrf),
            session_id: String(current.callToken || ''),
            evento: evento,
            numero: String(current.destination || '') === 'Número desconocido'
                ? '' : String(current.destination || ''),
            caller_name: String(current.context.callerName || '')
        });
        sdkJournalQueue = sdkJournalQueue.catch(function () {}).then(async function () {
            for (let attempt = 0; attempt < 2; attempt++) {
                try {
                    const response = await fetch(String(config.analystSdkJournalUrl), {
                        method: 'POST', credentials: 'same-origin',
                        cache: 'no-store',
                        headers: {
                            Accept: 'application/json',
                            'X-Requested-With': 'fetch',
                            'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8'
                        },
                        body: data.toString()
                    });
                    const result = await response.json();
                    if (!response.ok || result.ok !== true) {
                        throw new Error(result.mensaje || 'Registro WebRTC no disponible.');
                    }
                    return;
                } catch (error) {
                    if (attempt === 1) console.warn(
                        '[Analista] No se guardó el evento WebRTC ' + evento + '.', error
                    );
                }
            }
        });
    };

    const clearSdkOnlyIncoming = function (reason) {
        if (!state.active || !isSdkOnlyIncoming()) return;
        const previous = state;
        const answered = previous.phase === 'in-progress' && answeredAtMs > 0;
        const elapsed = answered ? currentDuration() : 0;
        reportSdkEvent(reason === 'declined' ? 'declined' :
            (answered ? 'ended' : 'missed'), previous);
        answeredAtMs = 0;
        muted = false;
        publish({
            phase: 'incoming-finished',
            active: false,
            muted: false,
            direction: 'incoming',
            status: answered ? 'completed' :
                (reason === 'declined' ? 'declined' : 'no-answer'),
            duration: elapsed,
            answeredAtMs: 0,
            message: 'Llamada finalizada. Registro WebRTC sin confirmación PBX.',
            finalMetadata: null
        });
    };

    const startAnalystSdkObserver = function () {
        // Las llamadas internas 100 → 101 pueden timbrar en el SDK sin
        // NOTIFY_INTERNAL. La DOM observada es evidencia de timbrado local,
        // pero NUNCA constituye una llamada PBX verificada.
        if (!config.analystSdkIncoming || !modoEmbebido ||
            !permiteEntrantes || analystSdkObserver) return;

        analystSdkObserver = window.IMPE_ZADARMA_SDK_RING_OBSERVER?.create(
            document, {
                onRinging: function (call) {
                    if (!widgetReady || state.active) return;
                    const number = String(call?.caller || '').slice(0, 40);
                    const token = 'sdk-' + Date.now().toString(36) +
                        '-' + Math.random().toString(36).slice(2, 9);
                    answeredAtMs = 0;
                    muted = false;
                    reportSdkEvent('ringing', {
                        callToken: token,
                        destination: number,
                        context: {
                            type: 'SDK_INCOMING',
                            callerName: String(call?.callerName || '').slice(0, 100)
                        }
                    });
                    publish({
                        active: true,
                        phase: 'incoming-ringing',
                        status: 'incoming-ringing',
                        direction: 'incoming',
                        destination: number || 'Número desconocido',
                        calledDid: '',
                        muted: false,
                        duration: 0,
                        requestedAt: Math.floor(Date.now() / 1000),
                        pbxCallId: '',
                        callToken: token,
                        context: {
                            type: 'SDK_INCOMING',
                            verified: false,
                            callerName: String(call?.callerName || '').slice(0, 100)
                        },
                        finalMetadata: null,
                        message: 'Timbrado detectado en el teléfono WebRTC. Registro PBX pendiente.'
                    });
                },
                onStopped: function () {
                    if (!isSdkOnlyIncoming()) return;
                    // Al atender, el SDK deja de timbrar pero la conversación
                    // CONTINÚA. Sólo limpiar un timbrado que no se contestó.
                    if (state.phase === 'incoming-ringing') {
                        clearSdkOnlyIncoming('missed');
                    }
                },
                onIdle: function () {
                    // El widget volvió al reposo (sin «incoming» ni control
                    // de colgar durante 2 s). Esto cubre cuando la OTRA
                    // extensión termina una conversación sin webhook PBX.
                    if (isSdkOnlyIncoming()) clearSdkOnlyIncoming('remote');
                }
            }
        ) || null;
    };

    const ensureWidget = async function () {
        if (
            widgetReady &&
            window.zdrmWebPhone &&
            typeof window.zdrmWebPhone.regToCall === 'function'
        ) {
            return;
        }

        if (location.protocol !== 'https:') {
            throw new Error(
                'La telefonía WebRTC requiere abrir el sistema mediante HTTPS.'
            );
        }

        if (!webRtcKey || !sipLogin) {
            await fetchWebRtcSession();
        }

        await loadScript(sdkLibUrl, 'lib');
        await loadScript(sdkFnUrl, 'fn');

        const loadersReady = await waitFor(function () {
            return (
                typeof window.zadarmaWidgetFn === 'function' &&
                typeof window.zdrmWebrtcPhoneInterface === 'function'
            );
        }, 15000, 100);

        if (!loadersReady) {
            throw new Error(
                'El componente WebRTC de Zadarma no terminó de cargar.'
            );
        }

        if (!widgetInitialized) {
            window.zadarmaWidgetFn(
                webRtcKey,
                sipLogin,
                'rounded',
                'es',
                true,
                "{right:'10px',bottom:'10px'}"
            );
            widgetInitialized = true;
            ocultarInterfazNativa();
        }

        const apiReady = await waitFor(function () {
            return (
                window.zdrmWebPhone &&
                typeof window.zdrmWebPhone.regToCall === 'function'
            );
        }, 15000, 100);

        if (!apiReady) {
            throw new Error(
                'Zadarma no publicó el control WebRTC para este dominio.'
            );
        }

        widgetReady = true;
        // Sólo se accede al DOM nativo para ejecutar un clic verificado.
        // No se modifican tamaños, posiciones ni estilos internos del SDK.
        incomingBridge = window.IMPE_ZADARMA_NATIVE_BRIDGE_FACTORY?.create(
            document,
            function () {
                return state.active && state.direction === 'incoming' &&
                    state.phase === 'incoming-ringing' && widgetReady;
            },
            function () {
                return state.active && state.direction === 'incoming' &&
                    state.phase === 'in-progress' && widgetReady;
            }
        ) || null;
        startIncomingPolling();
        startAnalystSdkObserver();

        const staged =
            !state.active &&
            state.destination &&
            state.context;

        publish({
            phase:
                state.active
                    ? state.phase
                    : (
                        staged
                            ? 'prepared'
                            : 'ready'
                    ),
            status:
                staged
                    ? 'ready'
                    : state.status,
            message: ''
        });
    };

    // Cuando el usuario actúa, consultar el estado PBX sin esperar el
    // siguiente ciclo normal (1.8 segundos). No reiniciar el SDK SIP.
    const actualizarEstadoTrasAccion = function () {
        [100, 500, 1050].forEach(function (delay) {
            window.setTimeout(function () {
                if (state.direction === 'incoming' && state.active) {
                    void pollIncoming();
                }
            }, delay);
        });
    };

    window.IMPE_ZADARMA_INCOMING = Object.freeze({
        canAnswer: function () {
            return Boolean(permiteEntrantes && incomingBridge?.available());
        },
        answer: function () {
            const sdkOnly = isSdkOnlyIncoming() &&
                state.phase === 'incoming-ringing';
            const token = String(state.callToken || '');
            const sent = Boolean(permiteEntrantes && incomingBridge?.answer());
            if (!sent) return false;

            if (sdkOnly && state.active &&
                String(state.callToken || '') === token) {
                // La aceptación SIP la ejecutó el SDK y la llamada sigue en
                // el MISMO modal. Este estado visual NO es prueba de un PBX ID
                // ni autoriza registros/historial de interacción comercial.
                answeredAtMs = Date.now();
                reportSdkEvent('answered', state);
                publish({
                    phase: 'in-progress',
                    status: 'in-progress',
                    answeredAtMs: answeredAtMs,
                    duration: 0,
                    message: '',
                    context: Object.assign({}, state.context, {
                        verified: false,
                        source: 'SDK_WEBRTC'
                    })
                });
            }
            actualizarEstadoTrasAccion();
            return true;
        },
        decline: function () {
            const sdkOnly = isSdkOnlyIncoming() &&
                state.phase === 'incoming-ringing';
            const sent = Boolean(permiteEntrantes && incomingBridge?.decline());
            if (sent) {
                if (sdkOnly) clearSdkOnlyIncoming('declined');
                else actualizarEstadoTrasAccion();
            }
            return sent;
        },
        canHangup: function () {
            return Boolean(permiteEntrantes && incomingBridge?.canHangup());
        },
        hangup: function () {
            const sdkOnly = isSdkOnlyIncoming() &&
                state.phase === 'in-progress';
            const sent = Boolean(permiteEntrantes && incomingBridge?.hangup());
            if (sent) {
                if (sdkOnly) clearSdkOnlyIncoming('local');
                else actualizarEstadoTrasAccion();
            }
            return sent;
        },
        error: function () {
            return String(incomingBridge?.error() ||
                'No fue posible ejecutar el control WebRTC.');
        }
    });

    const stopPolling = function () {
        if (pollInterval) {
            clearInterval(pollInterval);
            pollInterval = null;
        }

        pollBusy = false;
    };

    const fetchIncomingState = async function () {
        if (
            !permiteEntrantes ||
            !String(config.entradaUrl || '').trim()
        ) {
            return null;
        }

        const params = new URLSearchParams({
            since: String(
                Math.floor(
                    (Date.now() - 10 * 60 * 1000) /
                    1000
                )
            )
        });

        if (
            state.direction === 'incoming' &&
            state.active &&
            String(state.pbxCallId || '').trim() !== ''
        ) {
            params.set(
                'pbx_call_id',
                String(state.pbxCallId)
            );
        }

        const response = await fetch(
            String(config.entradaUrl) +
                '?' +
                params.toString(),
            {
                method: 'GET',
                credentials: 'same-origin',
                cache: 'no-store',
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'fetch'
                }
            }
        );
        const data = await response.json();

        if (!response.ok || !data.ok) {
            throw new Error(
                data.mensaje ||
                'No fue posible consultar llamadas entrantes.'
            );
        }

        return data.call || null;
    };

    const finalizarEntrante = function (
        call,
        transferred
    ) {
        const token =
            String(
                call?.pbx_call_id ||
                state.pbxCallId ||
                ''
            );

        window.clearTimeout(
            incomingFinishedTimer
        );

        publish({
            phase: 'incoming-finished',
            active: false,
            direction: 'incoming',
            status: transferred
                ? 'transferred'
                : String(
                    call?.disposition ||
                    'completed'
                ),
            duration: Math.max(
                0,
                Number(call?.duration || 0),
                currentDuration()
            ),
            pbxCallId: token,
            calledDid:
                String(
                    call?.called_did ||
                    state.calledDid ||
                    ''
                ),
            message: transferred
                ? (
                    'Llamada transferida a extensión ' +
                    String(
                        call?.transfer_to || 'destino'
                    ) +
                    '.'
                )
                : ''
        });

        answeredAtMs = 0;
        ocultarHostEntrante();
        marcarPestanaEntrante('');

        incomingFinishedTimer =
            window.setTimeout(
                function () {
                    if (
                        state.direction === 'incoming' &&
                        !state.active &&
                        String(state.pbxCallId || '') ===
                            token
                    ) {
                        state = emptyState();
                        state.hostReady = true;
                        state.phase = widgetReady
                            ? 'ready'
                            : 'idle';
                        publish(state);
                    }
                },
                5000
            );
    };

    const marcarPestanaEntrante = function (numero) {
        if (!modoPestana) return;
        document.title = numero
            ? '☎ Llamada entrante · Ext. ' + extension
            : 'Teléfono conectado · Ext. ' + extension;
        const indicador = document.querySelector('.telephony-host-tab-status');
        if (indicador) {
            indicador.textContent = numero
                ? 'Llamada entrante de ' + numero
                : 'Recepción lista. Mantén abierta esta pestaña.';
        }
    };

    const applyIncomingState = function (call) {
        if (!call) {
            return;
        }

        const pbxCallId =
            String(call.pbx_call_id || '').trim();
        const incomingStatus =
            String(call.estado || '').trim();

        if (pbxCallId === '') {
            return;
        }

        /*
         * Una llamada saliente activa nunca se sustituye por una entrada
         * distinta en el mismo host.
         */
        if (
            state.active &&
            state.direction !== 'incoming' &&
            String(state.pbxCallId || '') !==
                pbxCallId
        ) {
            return;
        }

        if (incomingStatus === 'transferred') {
            if (
                state.direction === 'incoming' &&
                String(state.pbxCallId || '') ===
                    pbxCallId
            ) {
                finalizarEntrante(call, true);
            }
            return;
        }

        if (incomingStatus === 'ended') {
            if (
                state.direction === 'incoming' &&
                String(state.pbxCallId || '') ===
                    pbxCallId
            ) {
                finalizarEntrante(call, false);
            }
            return;
        }

        const answered =
            incomingStatus === 'answered';

        const nuevaLlamadaEntrante =
            state.direction !== 'incoming' ||
            String(state.pbxCallId || '') !==
                pbxCallId;

        if (nuevaLlamadaEntrante) {
            answeredAtMs = 0;
        }

        if (
            answered &&
            answeredAtMs <= 0
        ) {
            const answerAt =
                Date.parse(
                    String(call.answer_at || '')
                        .replace(' ', 'T')
                );
            answeredAtMs =
                Number.isFinite(answerAt)
                    ? answerAt
                    : Date.now();
        }

        publish({
            phase: answered
                ? 'in-progress'
                : 'incoming-ringing',
            active: true,
            // No reiniciar el silencio en cada sondeo del webhook PBX.
            muted: nuevaLlamadaEntrante ? false : Boolean(state.muted),
            direction: 'incoming',
            destination:
                String(call.caller_id || ''),
            calledDid:
                String(call.called_did || ''),
            institution: '',
            status: answered
                ? 'in-progress'
                : 'incoming-ringing',
            // Antes de contestar, el reloj siempre comienza en 00:00.
            // Si ya se contestó, el valor pertenece al PBX call actual.
            duration: answered
                ? Math.max(0, Number(call.duration || 0))
                : 0,
            requestedAt: nuevaLlamadaEntrante
                ? Math.floor(Date.now() / 1000)
                : Number(state.requestedAt || Math.floor(Date.now() / 1000)),
            answeredAtMs: answeredAtMs,
            pbxCallId: pbxCallId,
            callToken: pbxCallId,
            context: {
                type: 'INCOMING',
                callerId:
                    String(call.caller_id || ''),
                calledDid:
                    String(call.called_did || '')
            },
            finalMetadata: null,
            message: answered
                ? ''
                : 'Llamada entrante a la extensión ' +
                    extension
        });

        if (nuevaLlamadaEntrante) {
            marcarPestanaEntrante(String(call.caller_id || ''));
            mostrarHostEntrante();
        }
    };

    const pollIncoming = async function () {
        if (
            !permiteEntrantes ||
            incomingPollBusy ||
            (
                state.active &&
                state.direction !== 'incoming'
            )
        ) {
            return;
        }

        incomingPollBusy = true;

        try {
            const call =
                await fetchIncomingState();

            if (call) {
                applyIncomingState(call);
            }
        } catch (error) {
            console.debug(
                'No fue posible consultar la llamada entrante.',
                error
            );
        } finally {
            incomingPollBusy = false;
        }
    };

    const startIncomingPolling = function () {
        if (
            !permiteEntrantes ||
            incomingPollInterval
        ) {
            return;
        }

        void pollIncoming();

        incomingPollInterval =
            window.setInterval(
                pollIncoming,
                1800
            );
    };

    const isFinal = function (status) {
        return [
            'completed',
            'busy',
            'no-answer',
            'failed',
            'canceled'
        ].includes(String(status || ''));
    };

    /**
     * Reserva un punto de partida en los eventos PBX ANTES de marcar.
     * Sin esto, una devolución rápida al mismo número podría heredar
     * la finalización de la llamada anterior.
     */
    const fetchDialCheckpoint = async function (destination) {
        const params = new URLSearchParams({
            destination: destination,
            checkpoint: '1'
        });
        const response = await fetch(
            String(config.estadoUrl || '') + '?' + params.toString(),
            {
                method: 'GET',
                credentials: 'same-origin',
                cache: 'no-store',
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'fetch'
                }
            }
        );
        const data = await response.json();
        if (!response.ok || !data.ok) {
            throw new Error(
                data.mensaje ||
                'No fue posible preparar el seguimiento de esta llamada.'
            );
        }
        return Math.max(0, Number(data.after_id || 0));
    };

    const fetchCallState = async function (
        forceFinal,
        useStatisticsFallback
    ) {
        if (!state.destination || !state.requestedAt) {
            return null;
        }

        const params = new URLSearchParams({
            destination: state.destination,
            since: String(state.requestedAt)
        });

        if (state.checkpointReady) {
            params.set('after_id', String(state.afterEventId || 0));
        }

        if (forceFinal) {
            params.set('final', '1');

            if (useStatisticsFallback === true) {
                params.set('stats', '1');
            }
        }

        const response = await fetch(
            String(config.estadoUrl || '') + '?' + params.toString(),
            {
                method: 'GET',
                credentials: 'same-origin',
                cache: 'no-store',
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'fetch'
                }
            }
        );
        const data = await response.json();

        if (!response.ok || !data.ok) {
            throw new Error(
                data.mensaje ||
                'No fue posible consultar el estado de la llamada.'
            );
        }

        return data.call || null;
    };

    /**
     * Desconecta el audio del SDK además de cambiar el estado del CRM.
     * La respuesta del webhook, por sí sola, no cuelga el WebRTC local.
     */
    const endOutgoingWebrtc = function (cancelEarly) {
        if (state.direction !== 'outgoing') {
            return;
        }
        const sdk = window.zdrmWebPhone;
        if (!sdk) {
            return;
        }

        try {
            if (typeof sdk.finishCall === 'function') {
                sdk.finishCall();
            }
        } catch (error) {
            console.warn('No fue posible finalizar WebRTC.', error);
        }

        if (cancelEarly && typeof sdk.regToCancel === 'function') {
            try {
                sdk.regToCancel();
            } catch (error) {
                console.warn('No fue posible cancelar el marcado.', error);
            }
        }
    };

    const finishCall = function (call, fallbackStatus) {
        stopPolling();
        endOutgoingWebrtc(false);
        hangingUp = false;

        const finalCall = call || lastCall || {};
        let finalStatus = String(
            isFinal(finalCall.status)
                ? finalCall.status
                : (fallbackStatus || 'failed')
        );
        const duration = Math.max(
            currentDuration(),
            Number(finalCall.duration || 0)
        );

        if (
            duration > 0 &&
            ['ringing', 'in-progress', 'dialing', ''].includes(finalStatus)
        ) {
            finalStatus = 'completed';
        }

        const callIdWithRec =
            String(finalCall.call_id_with_rec || '').trim();
        const isRecorded =
            finalCall.is_recorded === true ||
            String(finalCall.is_recorded || '') === '1';
        const recordReady =
            finalCall.record_ready === true ||
            String(finalCall.record_ready || '') === '1';

        let recordingState = 'none';

        if (recordReady) {
            recordingState = 'available';
        } else if (
            finalStatus === 'completed' &&
            duration > 0 &&
            (isRecorded || callIdWithRec !== '' || answeredAtMs > 0)
        ) {
            recordingState = 'processing';
        }

        const finalMetadata = {
            seguimiento_id: Number(state.context?.seguimientoId || 0),
            pbx_call_id: String(
                finalCall.pbx_call_id || state.pbxCallId || ''
            ),
            status: finalStatus,
            duration: duration,
            start_time: finalCall.start_time || null,
            end_time: finalCall.end_time || null,
            requested_at: Number(state.requestedAt || 0),
            to: finalCall.destination || state.destination || '',
            is_recorded: isRecorded,
            record_ready: recordReady,
            recording_state: recordingState,
            call_id_with_rec: callIdWithRec,
            call_token: state.callToken
        };

        answeredAtMs = 0;
        muted = false;

        publish({
            phase: 'finished',
            active: false,
            muted: false,
            status: finalStatus,
            duration: duration,
            answeredAtMs: 0,
            pbxCallId: finalMetadata.pbx_call_id,
            finalMetadata: finalMetadata,
            message: ''
        });
        if (manualCall(state)) {
            const token = String(state.callToken || '');
            // Resumen temporal de Marketing: conservar 8 segundos para que
            // pueda comprobar la duración, luego limpiar estado persistido.
            // Seguimiento y Vinculación NO se limpian automáticamente.
            setTimeout(function () {
                if (!state.active && state.phase === 'finished' &&
                    String(state.callToken || '') === token) {
                    clearFinished(token);
                }
            }, 8000);
        }
    };

    const applyCallState = function (call) {
        if (!call || !state.active) {
            return;
        }

        lastCall = call;
        const status = String(call.status || 'ringing');
        const providerDuration =
            Math.max(0, Number(call.duration || 0));

        if (status === 'in-progress' && answeredAtMs <= 0) {
            answeredAtMs =
                Date.now() - (providerDuration * 1000);
        }

        publish({
            phase:
                status === 'in-progress'
                    ? 'in-progress'
                    : (
                        status === 'ringing'
                            ? 'ringing'
                            : state.phase
                    ),
            status: status,
            duration: Math.max(currentDuration(), providerDuration),
            answeredAtMs: answeredAtMs,
            pbxCallId: String(
                call.pbx_call_id || state.pbxCallId || ''
            )
        });

        if (isFinal(status)) {
            finishCall(call, status);
        }
    };

    const poll = async function () {
        if (!state.active || pollBusy) {
            return;
        }

        const token = String(state.callToken || '');
        pollBusy = true;

        try {
            const call = await fetchCallState(
                false,
                false
            );

            // Una respuesta tardía nunca debe finalizar la nueva marcación.
            if (!state.active || String(state.callToken || '') !== token) {
                return;
            }

            if (call) {
                applyCallState(call);
            }
        } catch (error) {
            console.warn(error);
        } finally {
            pollBusy = false;
        }
    };

    const startPolling = function () {
        stopPolling();
        void poll();
        pollInterval = setInterval(poll, 800);
    };

    const randomToken = function () {
        if (
            window.crypto &&
            typeof window.crypto.randomUUID === 'function'
        ) {
            return window.crypto.randomUUID();
        }

        return Date.now().toString(36) +
            '-' +
            Math.random().toString(36).slice(2);
    };

    /**
     * Esta PBX utiliza 10 dígitos nacionales para destinos de México.
     * Los números se mantienen con +52 para identificar y correlacionar
     * llamadas dentro del CRM, pero WebRTC reside en una ventana distinta.
     * Las extensiones y los números internacionales no cambian.
     */
    const normalizarDestinoWebrtc = function (valor) {
        let destino = String(valor || '').trim()
            .replace(/[^0-9+*#]/g, '');

        if (/^\+?521\d{10}$/.test(destino)) {
            destino = destino.replace(/^\+?521/, '');
        } else if (/^\+?52\d{10}$/.test(destino)) {
            destino = destino.replace(/^\+?52/, '');
        } else {
            destino = destino.replace(/^\+/, '');
        }

        return destino;
    };

    const startCall = async function (payload) {
        if (!permiteSalientes) {
            publish({
                phase: 'error',
                message:
                    'Esta extensión no tiene habilitadas llamadas salientes.'
            });
            return;
        }

        if (state.active) {
            publish({
                message: 'Ya existe una llamada activa en esta extensión.'
            });
            return;
        }

        const destination =
            String(payload.destination || '').trim();

        if (destination === '') {
            publish({
                phase: 'error',
                message: 'El número telefónico no es válido.'
            });
            return;
        }

        try {
            await ensureWidget();
            const afterEventId = await fetchDialCheckpoint(destination);

            if (state.active) {
                throw new Error('Ya existe una llamada activa en esta extensión.');
            }

            muted = false;
            answeredAtMs = 0;
            lastCall = null;
            hangingUp = false;

            const requestedAt = Math.floor(Date.now() / 1000);
            const callToken = randomToken();

            publish({
                phase: 'dialing',
                active: true,
                muted: false,
                direction: 'outgoing',
                calledDid: '',
                destination: destination,
                institution: String(payload.institution || '').trim(),
                status: 'dialing',
                duration: 0,
                requestedAt: requestedAt,
                afterEventId: afterEventId,
                checkpointReady: true,
                answeredAtMs: 0,
                pbxCallId: '',
                callToken: callToken,
                context: payload.context || null,
                finalMetadata: null,
                message: ''
            });

            const result = window.zdrmWebPhone.regToCall(
                normalizarDestinoWebrtc(destination)
            );

            if (typeof result === 'string' && result.trim() !== '') {
                throw new Error(result.trim());
            }

            startPolling();
        } catch (error) {
            stopPolling();

            publish({
                phase: 'error',
                active: false,
                status: 'failed',
                message:
                    error.message ||
                    'No fue posible iniciar la llamada.'
            });
        }
    };

    const hangup = async function () {
        if (!state.active || hangingUp) {
            return;
        }

        hangingUp = true;

        publish({
            phase: 'finishing',
            status: 'finishing'
        });

        // Al hablar (incluido el buzón) se finaliza la sesión de audio;
        // si aún marca, también se cancela el intento pendiente.
        endOutgoingWebrtc(
            String(state.status || '') !== 'in-progress' &&
            Number(state.answeredAtMs || 0) <= 0
        );

        for (let attempt = 0; attempt < 6; attempt += 1) {
            await sleep(attempt === 0 ? 900 : 700);

            if (!state.active) {
                return;
            }

            try {
                const call = await fetchCallState(
                    true,
                    attempt === 2
                );

                if (call) {
                    lastCall = call;

                    if (isFinal(call.status)) {
                        finishCall(call, call.status);
                        return;
                    }
                }
            } catch (error) {
                console.warn(error);
            }
        }

        finishCall(
            lastCall,
            currentDuration() > 0 ? 'completed' : 'canceled'
        );
    };

    const clearFinished = function (callToken) {
        if (
            state.active ||
            (
                callToken &&
                state.callToken &&
                String(callToken) !== String(state.callToken)
            )
        ) {
            return;
        }

        // Cerrar un resumen no debe reiniciar ni colgar la conexión SIP.
        window.clearTimeout(incomingFinishedTimer);
        endOutgoingWebrtc(false);
        state = emptyState();
        state.hostReady = true;
        state.phase = widgetReady ? 'ready' : 'idle';
        publish(state);
    };

    const sendTransferDtmf = function (
        targetExtension,
        attended
    ) {
        const ext =
            String(targetExtension || '')
                .replace(/\D+/g, '');

        if (
            !permiteTransferir ||
            ext.length < 3 ||
            ext.length > 6 ||
            !state.active
        ) {
            return false;
        }

        const code =
            attended
                ? '*' + ext + '#'
                : '#' + ext + '#';

        const candidates = [
            window.zdrmWebPhone,
            window.zdrmWebrtcPhoneInterface
        ].filter(Boolean);
        const methods = [
            'sendDtmf',
            'sendDTMF',
            'regToDtmf',
            'regToDTMF',
            'dtmf'
        ];

        for (const target of candidates) {
            for (const method of methods) {
                if (
                    typeof target?.[method] ===
                    'function'
                ) {
                    try {
                        const result =
                            target[method](code);

                        if (result === false) {
                            continue;
                        }

                        publish({
                            message:
                                attended
                                    ? 'Consultando extensión ' +
                                        ext +
                                        '…'
                                    : 'Transfiriendo a extensión ' +
                                        ext +
                                        '…'
                        });
                        return true;
                    } catch (error) {
                        console.debug(
                            'El control WebRTC no aceptó DTMF mediante ' +
                                method +
                                '.',
                            error
                        );
                    }
                }
            }
        }

        /*
         * El widget oficial siempre soporta la combinación DTMF de la PBX.
         * Si su versión no expone un método JS documentado, mostramos el
         * control oficial y el código exacto que debe marcarse.
         */
        mostrarHostEntrante();
        publish({
            message:
                'Marca ' +
                code +
                ' en el teclado de Zadarma para completar la transferencia.'
        });

        return false;
    };

    const processCommand = function (message) {
        if (!message || message.type !== 'COMMAND') {
            return;
        }

        const commandId = String(message.id || '');

        if (commandId && commandId === lastCommandId) {
            return;
        }

        lastCommandId = commandId;
        const action = String(message.action || '');
        const payload = message.payload || {};

        if (action === 'PING') {
            publish({});
        } else if (
            action === 'STAGE' &&
            !state.active
        ) {
            publish({
                phase: 'prepared',
                active: false,
                status: 'ready',
                duration: 0,
                destination:
                    String(payload.destination || '').trim(),
                institution:
                    String(payload.institution || '').trim(),
                context:
                    payload.context || null,
                finalMetadata: null,
                message: ''
            });
        } else if (
            action === 'CANCEL_STAGE' &&
            !state.active
        ) {
            state = emptyState();
            state.hostReady = true;
            state.phase =
                widgetReady
                    ? 'ready'
                    : 'idle';
            state.status = 'idle';
            publish(state);
        } else if (action === 'START') {
            void startCall(payload);
        } else if (action === 'HANGUP') {
            void hangup();
        } else if (action === 'MUTE' && state.active) {
            setMuted(Boolean(payload.muted));
        } else if (
            action === 'TRANSFER' &&
            state.active
        ) {
            sendTransferDtmf(
                payload.extension,
                Boolean(payload.attended)
            );
        } else if (action === 'SHOW_CONTROLS') {
            if (state.active) {
                mostrarHostEntrante();
                if (modoEmbebido && window.parent !== window) {
                    window.parent.postMessage({
                        type: 'IMPE_TELEPHONY_NATIVE_CONTROLS',
                        userId: userId
                    }, window.location.origin);
                }
            }
        } else if (action === 'CLEAR_FINISHED') {
            clearFinished(String(payload.callToken || ''));
        }
    };

    if (channel) {
        channel.addEventListener('message', function (event) {
            processCommand(event.data);
        });
    }

    addEventListener('storage', function (event) {
        if (event.key !== commandKey || !event.newValue) {
            return;
        }

        try {
            processCommand(JSON.parse(event.newValue));
        } catch (error) {
            console.debug('Comando telefónico inválido.', error);
        }
    });

    addEventListener('beforeunload', function (event) {
        if (!state.active) {
            return;
        }

        event.preventDefault();
        event.returnValue = '';
    });

    captureMicrophone();

    publish({
        phase: 'loading',
        active: false,
        message:
            permiteEntrantes
                ? 'Preparando recepción en extensión ' +
                    extension +
                    '…'
                : 'Preparando extensión ' +
                    extension +
                    '…'
    });

    void ensureWidget().then(function () {
        marcarPestanaEntrante('');
    }).catch(function (error) {
        if (modoPestana) {
            const indicador = document.querySelector('.telephony-host-tab-status');
            if (indicador) indicador.textContent =
                error.message || 'No fue posible conectar la recepción.';
        }
        publish({
            phase: 'error',
            active: false,
            message:
                error.message ||
                'No fue posible preparar el motor WebRTC.'
        });
    });

    setInterval(function () {
        publish({});
    }, 1500);

    setInterval(render, 500);
    render();
})();
