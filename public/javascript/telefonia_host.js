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
    if(window.IMPE_TELEPHONY_HOST_RUNTIME_STARTED)return;
    window.IMPE_TELEPHONY_HOST_RUNTIME_STARTED=true;

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
    let microphoneSession = null;
    let microphoneCallToken = '';

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

    // Nunca rehidratar una llamada como activa al abrir una pestaña nueva.
    // La nueva conexión SIP debe publicar evidencia fresca del SDK/PBX.
    const previousState = readState();
    let state = emptyState();
    let transferFlow=null;
    const retiredKey = 'impe:telephony:retired-call-ids:' + userId;
    const retiredIds = new Map();
    const declinedInvitations = new Map();
    const declinedInvitation = id => {
        if (!declinedInvitations.has(id)) return false;
        const previous=declinedInvitations.get(id),session=window.zdrmWebPhone?.webCallSession;
        if(session&&session!==previous&&session.direction==='incoming'&&!session.isEnded?.()){
            declinedInvitations.delete(id);return false;
        }
        return true;
    };
    try {
        const rows = JSON.parse(window.localStorage.getItem(retiredKey) || '[]');
        for (const row of Array.isArray(rows) ? rows : []) {
            if (Array.isArray(row) && Date.now() - Number(row[1]) < 30*60*1000)
                retiredIds.set(String(row[0]), Number(row[1]));
        }
    } catch (_) {}
    const retirePbxCall = id => {
        const token = String(id || '').trim();
        if (!/^[A-Za-z0-9_-]{3,100}$/.test(token)) return;
        retiredIds.set(token, Date.now());
        try {
            window.localStorage.setItem(retiredKey, JSON.stringify(
                Array.from(retiredIds).filter(row => Date.now()-row[1] < 30*60*1000)
                    .slice(-20)));
        } catch (_) {}
    };
    if (previousState.active ||
        ['finished','incoming-finished'].includes(previousState.phase)) {
        retirePbxCall(previousState.pbxCallId);
    }
    const manualCall = call =>
        ['TELEPHONY_MANUAL', 'MARKETING_CALLBACK', 'DIALER'].includes(
            String(call?.context?.type || '').toUpperCase()
        );
    // En un reinicio jamás conservar número, duración, comandos ni bandera
    // activa de la conexión WebRTC anterior. No reabrir "volver a llamar".
    if (previousState?.active) {
        state.message = 'Teléfono reconectándose. La llamada anterior no se restauró.';
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
            ['incoming-ringing', 'answering'].includes(state.phase)) return 0;
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
            sdkReady: widgetReady,
            hostReady: widgetReady && connection.snapshot().verified && mediaUsable(),
            connection: {...connection.snapshot(), microphone:microphonePermission},
            duration: currentDuration(),
            updatedAt: Date.now()
        });
        if (!ownsTransport) {render(); return state;}
        syncMicrophone();

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
        window.IMPE_CALL_AUDIO?.update(state);
        return state;
    };

    const connection = window.IMPE_PHONE_CONNECTION_FACTORY.create(window, () => publish({}));
    // El iframe comparte la reserva del shell; una pestaña de teléfono directa
    // también debe esperar el mismo Web Lock, nunca registrar un segundo motor.
    let ownsTransport = false, releaseTransport = null;
    try {ownsTransport = window.parent !== window &&
        window.parent.IMPE_TELEPHONY_SHELL?.ownsPhoneHost?.(window) === true;} catch (_) {}
    let microphonePermission = 'prompt';
    const mediaUsable = () => microphonePermission === 'granted' &&
        window.IMPE_CALL_AUDIO?.getStatus?.() === 'running';
    window.IMPE_CALL_AUDIO?.unlock();
    const permissionMessage = () => microphonePermission === 'denied'
        ? 'El micrófono está bloqueado. Permítelo en los ajustes de este sitio y pulsa Activar teléfono.'
        : 'Pulsa Activar teléfono para autorizar el micrófono y habilitar el audio del navegador.';
    void navigator.permissions?.query({name:'microphone'}).then(permission => {
        const update = () => {microphonePermission = permission.state; publish({});};
        update(); permission.addEventListener('change', update);
    }).catch(() => {});

    let initialization = null, startupFailures = 0, startupRetryAt = 0;
    const ensureWidget = function () {
        if (!ownsTransport) return Promise.reject(new Error('El teléfono está reservado en otra pestaña.'));
        if (initialization) return initialization;
        initialization = initializeWidget().then(() => {
            startupFailures = 0; startupRetryAt = 0;
        }).finally(() => { initialization = null; });
        return initialization;
    };
    let healthTask = null;
    const connectionStartedAt = Date.now();
    const performHealthCheck = async function () {
        if (!ownsTransport) return connection.snapshot();
        // Un temporizador retrasado no termina una llamada ni recrea el SDK.
        const liveSession = window.zdrmWebPhone?.webCallSession;
        const active = state.active || Boolean(liveSession && !liveSession.isEnded?.());
        try {
            if (!widgetReady && !active && navigator.onLine !== false && Date.now() >= startupRetryAt) {
                await ensureWidget();
            }
            const health = connection.recover(active);
            if (!active) publish({phase:health.verified && ['error','initializing','idle'].includes(state.phase) ? 'ready' : state.phase,
                message:health.verified ? (mediaUsable() ? '' : permissionMessage()) :
                !health.online ? 'Sin conexión de red. Reconectaremos al volver.' :
                Date.now()-connectionStartedAt > 30000 ?
                'No se ha confirmado la conexión con Zadarma. Reintentando; verifica la red y el acceso al proveedor.' :
                'Verificando conexión con Zadarma…'});
            return health;
        } catch (error) {
            startupRetryAt = Date.now() + Math.min(60000, 5000 * 2 ** Math.min(startupFailures++, 4));
            publish({phase:active ? state.phase : 'error', message:error.message || 'No se pudo conectar con Zadarma.'});
            return connection.snapshot();
        }
    };
    const checkConnection = function () {
        if (healthTask) return healthTask;
        healthTask = performHealthCheck().finally(() => {healthTask = null;});
        return healthTask;
    };
    window.IMPE_PHONE_CONNECTION = Object.freeze({
        snapshot: () => ({...connection.snapshot(), sdkReady:widgetReady,
            verified:connection.snapshot().verified && mediaUsable(), microphone:microphonePermission, audio:window.IMPE_CALL_AUDIO?.getStatus?.() || 'unavailable'}),
        check: checkConnection,
        activate: async () => {
            window.IMPE_CALL_AUDIO?.unlock();
            if (!mediaUsable() && !state.active) {
                try {
                    const stream = await navigator.mediaDevices.getUserMedia({audio:true});
                    stream.getTracks().forEach(track => track.stop());
                    microphonePermission = 'granted';
                } catch (_) {microphonePermission = 'denied'; publish({message:permissionMessage()});}
            }
            return checkConnection();
        }
    });

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
        const selector = 'script[data-impe-telephony-host-' + marker + ']';
        let script = document.querySelector(selector);
        const create = !script;
        if (!script) {
            script = document.createElement('script');
            script.src = src; script.async = true;
            script.setAttribute('data-impe-telephony-host-' + marker, '');
            // Conservar un script lento: no inyectar dos copias del proveedor.
            script.addEventListener('load', () => {script.dataset.loaded = '1';}, {once:true});
            script.addEventListener('error', () => script.remove(), {once:true});
        }
        if (script.dataset.loaded === '1') return Promise.resolve();
        return new Promise((resolve, reject) => {
            const finish = error => {
                window.clearTimeout(deadline);
                script.removeEventListener('load', loaded);
                script.removeEventListener('error', failed);
                if (error) reject(error); else resolve();
            };
            const loaded = () => finish();
            const failed = () => finish(new Error('No fue posible cargar el teléfono.'));
            const deadline = window.setTimeout(() => finish(new Error(
                'Zadarma tardó demasiado en responder. Se reintentará la conexión.')), 20000);
            script.addEventListener('load', loaded, {once:true});
            script.addEventListener('error', failed, {once:true});
            if (create) document.head.appendChild(script);
        });
    };

    // Sólo pistas enviadas por la sesión actual: nunca audio recibido ni
    // capturas auxiliares del navegador. enabled=false transmite silencio.
    const syncMicrophone = function () {
        const candidate = window.zdrmWebPhone?.webCallSession;
        const session = state.active && !candidate?.isEnded?.() ? candidate : null;
        const token = String(state.callToken || '');
        if (session !== microphoneSession || token !== microphoneCallToken || !state.active) {
            microphoneTracks.forEach(track => {
                if (track.readyState === 'live') track.enabled = true;
            });
            microphoneTracks.clear();
            microphoneSession = session;
            microphoneCallToken = token;
            muted = false;
        }
        let tracks = [];
        try {
            tracks = (session?.connection?.getSenders?.() || [])
                .map(sender => sender.track)
                .filter(track => track?.kind === 'audio' && track.readyState === 'live');
            // Una renegociación puede sustituir la pista de la misma llamada.
            if (muted) tracks.forEach(track => {
                if (!microphoneTracks.has(track)) {
                    track.enabled = false;
                    microphoneTracks.add(track);
                }
            });
        } catch (_) { tracks = []; }
        state.muted = tracks.length > 0 && tracks.every(track => !track.enabled);
        if (tracks.length) muted = state.muted;
        return tracks;
    };

    const setMuted = function (value) {
        const nextMuted = Boolean(value);
        const tracks = syncMicrophone();
        if (!state.active || !tracks.length) {
            publish({message:'No hay un micrófono de la llamada disponible para cambiar.'});
            return;
        }
        const previous = tracks.map(track => track.enabled);
        try {
            tracks.forEach(track => { track.enabled = !nextMuted; });
            if (!tracks.every(track => track.enabled === !nextMuted)) throw new Error('mute');
            tracks.forEach(track => microphoneTracks.add(track));
            muted = nextMuted;
            publish({message:''});
        } catch (_) {
            tracks.forEach((track, index) => { track.enabled = previous[index]; });
            publish({message:'No se pudo cambiar el micrófono de la llamada.'});
        }
    };

    const fetchWebRtcSession = async function () {
        const controller = new AbortController();
        const deadline = window.setTimeout(() => controller.abort(), 15000);
        try {
        const response = await fetch(String(config.webrtcUrl || ''), {
            signal:controller.signal,
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
                'No fue posible preparar el teléfono.'
            );
        }

        sipLogin = String(data.sip_login || '').trim();
        webRtcKey = String(data.webrtc_key || '').trim();
        } finally {window.clearTimeout(deadline);}
    };

    // Únicamente las llamadas detectadas por el SDK SIN identificador PBX
    // usan esta limpieza local; jamás se crea una llamada de historial falsa.
    const isSdkOnlyIncoming = function () {
        return Boolean(config.analystSdkIncoming &&
            state.direction === 'incoming' &&
            state.context?.type === 'SDK_INCOMING' &&
            !String(state.pbxCallId || '').trim());
    };

    const clearSdkOnlyIncoming = function () {
        if (!state.active || !isSdkOnlyIncoming()) return;
        answeredAtMs = 0;
        muted = false;
        state = emptyState();
        state.hostReady = widgetReady;
        state.phase = widgetReady ? 'ready' : 'idle';
        publish(state);
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
                    if (!widgetReady) return false;
                    const freshSip=Boolean(call?.sipSessionId &&
                        call.sipSessionId!==state.context?.sdkInviteId);
                    // Un INVITE nuevo acredita otro diálogo; un END PBX anterior
                    // puede seguir pendiente. No reemplazar una saliente local.
                    if(state.active&&!state.context?.declineRequested &&
                        !(state.direction==='incoming' && (freshSip||call?.pushInvitation) &&
                          ['incoming-ringing','finishing','recovering','rejecting'].includes(state.phase))) return false;
                    const number = String(call?.caller || '').slice(0, 40);
                    const token = 'sdk-' + Date.now().toString(36) +
                        '-' + Math.random().toString(36).slice(2, 9);
                    answeredAtMs = 0;
                    muted = false;
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
                            callerName: String(call?.callerName || '').slice(0, 100),
                            sdkInviteId:String(call?.sipSessionId||'')
                        },
                        finalMetadata: null,
                        message: 'Se detectó una llamada entrante. Su registro está pendiente.'
                    });
                },
                onSessionBound: function (evidence={}) {
                    // Una transferencia saliente de Recepción se observa
                    // primero como PUSH y puede recibir la RTCSession cuando
                    // ya está "in-progress" por NOTIFY_ANSWER de PBX.
                    // Guardar el ID de la sesión actual permite que su END
                    // cierre el modal al instante; no atribuirlo a otro INVITE.
                    const id=String(evidence.sessionId||'');
                    if(!id||!state.active||state.direction!=='incoming'||
                        !['SDK_INCOMING','INCOMING'].includes(state.context?.type)||
                        !['incoming-ringing','answering','in-progress','finishing','recovering'].includes(state.phase))return;
                    const prior=String(state.context?.sdkInviteId||'');
                    if(prior&&prior!==id)return;
                    if(!prior)publish({context:{...state.context,sdkInviteId:id}});
                },
                onConnected: function (evidence={}) {
                    // La llamada transferida puede recibir su PBX ID ANTES
                    // de que el SDK anuncie audio. La señal local sigue
                    // siendo válida sin inventar un NOTIFY_ANSWER firmado.
                    const sdkOnly = isSdkOnlyIncoming();
                    const pbxIncoming = state.active &&
                        state.direction === 'incoming' &&
                        state.context?.type === 'INCOMING' &&
                        String(state.pbxCallId || '') !== '';
                    if ((!sdkOnly && !pbxIncoming) || !state.active ||
                        !['incoming-ringing','answering'].includes(state.phase)) return;
                    answeredAtMs = Date.now();
                    publish({
                        phase:'in-progress', status:'in-progress',
                        answeredAtMs:answeredAtMs, duration:0, message:'',
                        context:Object.assign({},state.context,{
                            sdkConnected:true,
                            sdkInviteId:String(evidence.sessionId||state.context?.sdkInviteId||''),
                            verified:Boolean(state.context?.pbxAnswered),
                            source:'SDK_WEBRTC'
                        })
                    });
                },
                onStopped: function () {
                    if(transferFlow?.holdsIncoming()){void transferFlow.tick();return;}
                    // La desaparición de las clases CSS del timbre no
                    // acredita END SIP ni fin de PUSH. Antes esto borraba
                    // el modal de un intento legítimo sin dejar resultado.
                    // onIdle procesa la terminación verificada por el SDK.
                },
                onIdle: function (evidence={}) {
                    if(evidence.sessionId && state.context?.sdkInviteId &&
                        evidence.sessionId!==state.context.sdkInviteId)return;
                    // Cerrar la interfaz local con END SIP verificable, incluso
                    // cuando ya se adoptó una referencia PBX. Antes, la rama
                    // SDK_INCOMING se liberaba pero INCOMING quedaba varios
                    // minutos en «Esperando cierre de Zadarma» sin sesión SIP.
                    // Este cierre NO declara que Zadarma haya terminado la
                    // conversación ni altera la conciliación/historial PBX.
                    const sipSession = window.zdrmWebPhone?.webCallSession;
                    const observedId = String(evidence.sessionId || '');
                    const boundId = String(state.context?.sdkInviteId || '');
                    const sdkOnly = isSdkOnlyIncoming();
                    const attachedPbx = ['INCOMING','SDK_INCOMING'].includes(state.context?.type) &&
                        Boolean(String(state.pbxCallId || ''));
                    const sameSip = Boolean(observedId && (
                        (boundId && observedId === boundId) ||
                        (!boundId && sipSession?.direction === 'incoming' &&
                            String(sipSession.id || '') === observedId &&
                            sipSession.isEnded?.())
                    ));
                    const sipAlive = sipSession?.direction === 'incoming' &&
                        !sipSession.isEnded?.();
                    // JsSIP puede emitir ended/failed antes de que isEnded()
                    // cambie o antes de soltar webCallSession. Para Ventas,
                    // el evento terminal de la sesión SIP EXACTA prevalece
                    // sobre ese puntero transitoriamente activo.
                    // Si el teléfono ya tiene OTRO diálogo vivo, no cerrarlo.
                    const ownSalesTerminal = Boolean(config.salesSdkIncoming &&
                        evidence.sessionEnded && observedId && boundId === observedId &&
                        state.context?.transferReference &&
                        (!sipAlive || String(sipSession?.id || '') === observedId));
                    const sdkOnlyMatches = sdkOnly &&
                        (!observedId || (boundId && observedId === boundId) ||
                            (sipSession?.direction === 'incoming' &&
                                String(sipSession.id || '') === observedId &&
                                sipSession.isEnded?.()));
                    if ((evidence.sessionEnded || evidence.pushEnded) &&
                        state.active && state.direction === 'incoming' &&
                        (!sipAlive || ownSalesTerminal) && (sdkOnlyMatches || (attachedPbx &&
                            (sameSip || (evidence.pushEnded && !observedId &&
                                ['incoming-ringing','answering','finishing','rejecting'].includes(state.phase)))))) {
                        const ref = String(state.pbxCallId || state.context?.transferReference || '');
                        const wasAnswered = Boolean(state.context?.sdkConnected ||
                            state.context?.pbxAnswered || answeredAtMs > 0);
                        const duration = wasAnswered ? currentDuration() : 0;
                        answeredAtMs = 0;
                        if (ref) retirePbxCall(ref);
                        publish({active:false, phase:'incoming-finished',
                            status:attachedPbx?'ended-unconfirmed':(wasAnswered?'sdk-ended':'no-answer'), duration,
                            context:{...state.context,sdkEnded:true},
                            message:attachedPbx
                                ? 'La llamada terminó en el navegador. Falta confirmar su cierre en el historial.'
                                : 'La llamada terminó en el navegador. El historial se actualiza por separado.'});
                        ocultarHostEntrante();
                        marcarPestanaEntrante('');
                        return;
                    }
                    const answered=Boolean(state.context?.sdkConnected||state.context?.pbxAnswered||answeredAtMs>0);
                    if(state.active && state.direction==='incoming' && !answered &&
                        ['incoming-ringing','answering','rejecting'].includes(state.phase)){
                        publish({phase:'finishing',status:'ending',duration:0,
                            message:'El intento no tiene respuesta confirmada. Comprobando su cierre.'});
                        void pollIncoming();return;
                    }
                    if(state.context?.declineRequested){
                        if(isSdkOnlyIncoming()&&window.zdrmWebPhone?.webCallSession?.isEnded?.())clearSdkOnlyIncoming();
                        else void pollIncoming();
                        return;
                    }
                    if(transferFlow?.holdsIncoming()){void transferFlow.tick();return;}
                    // El audio local terminó. Esperar cierre PBX, pero no
                    // mantener la pantalla/cronómetro en conversación falsa.
                    if (isSdkOnlyIncoming()) {
                        if(state.phase==='incoming-ringing') {
                            // Reposo observado sin prueba de cierre SIP/PUSH:
                            // no descartar la llamada como si nunca existió.
                            // El cierre real se procesa arriba con sessionEnded
                            // o pushEnded, dejando constancia de no contestada.
                            publish({phase:'finishing',status:'ending',duration:0,
                                message:'El timbrado terminó. Comprobando el cierre de la llamada.'});
                            void pollIncoming();
                        } else {
                            publish({phase:'recovering',status:'recovering',
                                message:'Se interrumpió el audio del navegador. La centralita debe confirmar el estado.'});
                            void pollIncoming();
                        }
                        return;
                    }
                    if (!state.active || state.direction !== 'incoming' ||
                        !['incoming-ringing','answering','in-progress'].includes(state.phase) ||
                        !state.pbxCallId) return;
                    const token = String(state.pbxCallId);
                    const duration = currentDuration();
                    answeredAtMs = 0;
                    publish({
                        phase:'recovering',status:'recovering',duration,
                        answeredAtMs:0,
                        message:'Se interrumpió el audio del navegador. Comprobando el estado de la llamada.'
                    });
                    window.setTimeout(function () {
                        // La ausencia de audio no sustituye al cierre PBX.
                        if (state.active && state.phase === 'recovering' &&
                            String(state.pbxCallId) === token) {
                            // Perder el transporte SIP no acredita un END en PBX.
                            void pollIncoming();
                        }
                    },8000);
                }
            }
        ) || null;
    };

    const initializeWidget = async function () {
        if (
            widgetReady &&
            window.zdrmWebPhone &&
            typeof window.zdrmWebPhone.regToCall === 'function'
        ) {
            return;
        }

        if (location.protocol !== 'https:') {
            throw new Error(
                'Abre el sistema desde su dirección segura para utilizar el teléfono.'
            );
        }

        if (!webRtcKey || !sipLogin) {
            await fetchWebRtcSession();
        }

        // Registrar la conexión del SDK ANTES de que cree su WebRTC.
        // No mostrar ni sustituir el widget ni modificar métodos SIP.
        const dtmfBridge = window.IMPE_ZADARMA_WEBRTC_DTMF;
        if (dtmfBridge && !dtmfBridge.install()) {
            console.warn('DTMF: no se pudo preparar el navegador.');
        }
        await loadScript(sdkLibUrl, 'lib');
        await loadScript(sdkFnUrl, 'fn');

        const loadersReady = await waitFor(function () {
            return (
                typeof window.zadarmaWidgetFn === 'function' &&
                typeof window.zdrmWebrtcPhoneInterface === 'function' &&
                typeof window.io === 'function'
            );
        }, 15000, 100);

        if (!loadersReady) {
            throw new Error(
                'El componente del teléfono no terminó de cargar.'
            );
        }

        connection.install();
        if (!widgetInitialized) {
            window.zadarmaWidgetFn(
                webRtcKey,
                sipLogin,
                'rounded',
                'es',
                true,
                "{right:'-9999px',bottom:'-9999px'}"
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
                'El servicio telefónico no habilitó el teléfono para este sitio.'
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
                    ['in-progress','recovering','finishing'].includes(state.phase) && widgetReady &&
                    window.zdrmWebPhone?.webCallSession?.isEstablished?.() &&
                    !window.zdrmWebPhone.webCallSession.isEnded?.();
            },
            ()=>state.callToken
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
            const answeringIncoming = state.active &&
                state.direction === 'incoming' &&
                state.phase === 'incoming-ringing';
            const token = String(state.callToken || '');
            const sent = Boolean(permiteEntrantes && incomingBridge?.answer());
            if (!sent) return false;

            if (answeringIncoming && state.active &&
                String(state.callToken || '') === token) {
                analystSdkObserver?.markAnswerRequested?.();
                // Bloquear un segundo clic hasta tener una señal observada del
                // SDK. Pulsar otra vez durante el establecimiento SIP podía
                // cancelar la llamada mientras 'Contestar' reaparecía.
                publish({
                    phase:'answering',
                    status:'answering',
                    duration:0,
                    message:'Conectando llamada… Espera la confirmación del teléfono.'
                });
            }
            actualizarEstadoTrasAccion();
            return true;
        },
        decline: function () {
            if(state.context?.declineRequested)return true;
            const token=String(state.callToken||''),ref=String(state.pbxCallId||state.context?.transferReference||'');
            const session=window.zdrmWebPhone?.webCallSession;
            const hadSession=Boolean(session&&!session.isEnded?.());
            const sent = Boolean(permiteEntrantes && incomingBridge?.decline(token||ref));
            if (sent) {
                if(ref){declinedInvitations.set(ref,session);if(declinedInvitations.size>40)declinedInvitations.delete(declinedInvitations.keys().next().value);}
                if(String(state.callToken||'')===token)publish({phase:'rejecting',status:'rejecting',context:{...state.context,declineRequested:true},message:'Rechazo enviado. Esperando confirmación de la centralita.'});
                analystSdkObserver?.markDeclined?.(hadSession?session:null);
                actualizarEstadoTrasAccion();
            }
            return sent;
        },
        recheck: function () {
            if(!state.active)return false;
            if(state.direction==='incoming'){
                // Consultar no es volver a colgar: el usuario ya pidió
                // finalizar y repetir finishCall puede cancelar otro diálogo.
                analystSdkObserver?.checkNow?.();
                void pollIncoming();
            }else void poll();
            publish({message:'Comprobando el estado real en la centralita; todavía no se confirma el cierre.'});
            return true;
        },
        canHangup: function () {
            return Boolean(permiteEntrantes && incomingBridge?.canHangup());
        },
        hangup: function () {
            const sdkOnly = isSdkOnlyIncoming() &&
                state.phase === 'in-progress';
            const sent = Boolean(permiteEntrantes && incomingBridge?.hangup());
            if (sent) {
                if(sdkOnly)publish({phase:'finishing',status:'ending',message:'Cierre solicitado. Esperando que termine la llamada.'});
                actualizarEstadoTrasAccion();
            }
            return sent;
        },
        error: function () {
            return String(incomingBridge?.error() ||
                'No fue posible ejecutar la acción del teléfono.');
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

        // Sin referencia PBX no buscar diez minutos de historia: el mismo
        // número puede haber llamado o sido transferido varias veces. Sólo
        // descubrir eventos recibidos alrededor del INVITE SIP actual.
        // Con una referencia conocida la consulta sigue siendo exacta.
        const unresolvedSip = state.active && state.direction === 'incoming' &&
            state.context?.type === 'SDK_INCOMING' &&
            !String(state.pbxCallId || state.context?.transferReference || '').trim();
        const nowSeconds = Math.floor(Date.now() / 1000);
        const discoverySince = unresolvedSip
            ? Math.max(0, Number(state.requestedAt || nowSeconds) - 12)
            : (state.active ? nowSeconds - 600 : nowSeconds - 25);
        const params = new URLSearchParams({
            since: String(discoverySince)
        });

        if (
            state.direction === 'incoming' &&
            state.active &&
            (state.context?.type !== 'SDK_INCOMING' || state.context?.transferReference) &&
            String(state.context?.transferReference || state.pbxCallId || '').trim() !== ''
        ) {
            params.set(
                'pbx_call_id',
                String(state.context?.transferReference || state.pbxCallId)
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
        retirePbxCall(token);

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
        if(transferFlow?.holdsIncoming()&&call?.estado!=='ended')return;
        if(state.active && state.context?.type==='SDK_INCOMING' && /^\d{3,6}$/.test(state.destination) &&
            call?.transfer_from===state.destination && !state.context.operationChecked && config.operacionUrl)return;
        if (!call) {
            return;
        }

        const pbxCallId =
            String(call.pbx_call_id || '').trim();
        const incomingStatus =
            String(call.estado || '').trim();

        if (pbxCallId === '' ||
            (!state.active && retiredIds.has(pbxCallId))) {
            return;
        }
        if(incomingStatus!=='ended'&&declinedInvitation(pbxCallId))return;
        const rejectedRef=String(state.pbxCallId||state.context?.transferReference||'');
        if(state.context?.declineRequested&&rejectedRef&&pbxCallId!==rejectedRef&&incomingStatus==='ringing')analystSdkObserver?.allowNewInvitation?.();

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

        // Una operación autorizada puede entregar su referencia antes del
        // NOTIFY_INTERNAL. Adoptarla sólo si la consulta PBX propia coincide
        // exactamente; nunca por teléfono, hora o por terminar el SDK.
        if(state.active&&state.direction==='incoming'&&!state.pbxCallId&&
            state.context?.transferReference===pbxCallId)publish({pbxCallId});

        if (incomingStatus === 'transferred') {
            // En consulta la recepción todavía conversa con el destinatario.
            // NOTIFY_INTERNAL acredita el timbrado, no la entrega final.
            const session = window.zdrmWebPhone?.webCallSession;
            if (state.context?.transferRequest?.mode === 'CONSULTADA' &&
                session && typeof session.isEnded === 'function' && !session.isEnded()) {
                publish({message:'Consulta en curso con ext. ' + call.transfer_to +
                    '. Espera su respuesta; si no contesta, vuelve a recepción.'});
                return;
            }
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

        const samePbxCall = state.direction === 'incoming' &&
            String(state.pbxCallId || '') === pbxCallId;
        // Un sondeo "ringing" tardío nunca degrada una llamada que el SDK
        // ya confirmó en conversación, ni reinicia el reloj a cero.
        // El PBX sólo puede absorber una sesión SDK reciente de este host.
        const digits10 = value => String(value || '').replace(/\D+/g, '').slice(-10);
        const sameCaller = digits10(state.destination).length === 10 &&
            digits10(state.destination) === digits10(call.caller_id);
        const recentSdkAudio = state.active &&
            state.direction === 'incoming' &&
            state.context?.type === 'SDK_INCOMING' &&
            Boolean(state.context?.sdkConnected) &&
            answeredAtMs > 0 && Date.now() - answeredAtMs < 15000;
        const sdkAnswered = Boolean(state.active &&
            state.direction === 'incoming' && state.context?.sdkConnected &&
            (samePbxCall || (recentSdkAudio &&
                (sameCaller || (String(state.destination || '').replace(/\D+/g,'').length < 10 &&
                 analystSdkObserver?.isConnected?.())))));
        const sdkSessionId=state.context?.sdkSessionId||
            (state.context?.type==='SDK_INCOMING' && (sameCaller||state.context.transferReference===pbxCallId||state.destination===call.caller_id)?state.callToken:'');
        const pbxAnswered = incomingStatus === 'answered' ||
            (samePbxCall && Boolean(state.context?.pbxAnswered));
        // Una vez confirmada la conversación para ESTE PBX call ID,
        // los sondeos atrasados con estado "ringing" no pueden regresarla
        // a "Conectando" ni reiniciar su cronómetro.
        const previouslyConnected = samePbxCall && state.active &&
            state.phase === 'in-progress';
        const answered = pbxAnswered || sdkAnswered || previouslyConnected;
        const nuevaLlamadaEntrante = !samePbxCall;
        // La PBX puede tardar después de que el audio ya desapareció.
        if (samePbxCall && ['finishing','recovering'].includes(state.phase)) return;
        if (nuevaLlamadaEntrante && !sdkAnswered) {
            answeredAtMs = 0;
        } else if (sdkAnswered && answeredAtMs <= 0) {
            answeredAtMs = Number(state.answeredAtMs) || Date.now();
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
            // Sólo una marca razonablemente reciente puede iniciar
            // el reloj; jamás usar una fecha vieja que genere horas.
            answeredAtMs = Number.isFinite(answerAt) &&
                answerAt <= Date.now() && Date.now() - answerAt < 3600000
                ? answerAt : Date.now();
        }

        publish({
            phase: answered ? 'in-progress' :
                (samePbxCall && state.phase === 'answering'
                    ? 'answering' : 'incoming-ringing'),
            active: true,
            // No reiniciar el silencio en cada sondeo del webhook PBX.
            muted: nuevaLlamadaEntrante ? false : Boolean(state.muted),
            direction: 'incoming',
            destination:
                String(call.caller_id || ''),
            calledDid:
                String(call.called_did || ''),
            transferFrom:String(call.transfer_from || ''),
            institution: '',
            status: answered ? 'in-progress' :
                (samePbxCall && state.phase === 'answering'
                    ? 'answering' : 'incoming-ringing'),
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
                type:'INCOMING',
                callerId:String(call.caller_id || ''),
                calledDid:String(call.called_did || ''),
                sdkConnected:sdkAnswered || (samePbxCall &&
                    Boolean(state.context?.sdkConnected)),
                pbxAnswered:pbxAnswered,
                sdkSessionId:sdkSessionId,
                sdkInviteId:(samePbxCall||sdkSessionId)?String(state.context?.sdkInviteId||''):'',
                transferFrom:String(call.transfer_from || ''),
                transferRequest:samePbxCall && !call.returned_from?state.context?.transferRequest:null
            },
            finalMetadata: null,
            message: call.returned_from
                ? 'La llamada volvió a recepción desde la extensión '+call.returned_from+'.'
                : answered
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
            // Hostinger: recuperar onConnected perdido sólo con evidencia de audio SDK.
            if (state.active && state.direction === 'incoming' &&
                state.phase === 'answering' && analystSdkObserver?.isConnected?.()) {
                answeredAtMs = Date.now();
                publish({phase:'in-progress',status:'in-progress',answeredAtMs,duration:0,message:'',
                    context:Object.assign({},state.context,{sdkConnected:true,source:'SDK_WEBRTC',
                        verified:Boolean(state.context?.pbxAnswered)})});
            }
            const requestToken=state.callToken;
            const call =
                await fetchIncomingState();
            if(state.callToken!==requestToken)return;

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
        if(transferFlow?.sourcePending() && window.zdrmWebPhone?.webCallSession?.isEnded?.()){
            void transferFlow.tick();return;
        }
        stopPolling();
        if(!window.zdrmWebPhone?.webCallSession?.isEnded?.())endOutgoingWebrtc(false);
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

        if(state.phase==='recovering'&&!isFinal(status)){
            publish({pbxCallId:String(call.pbx_call_id||state.pbxCallId||''),message:'La centralita mantiene la llamada. Esperando recuperar el audio del navegador.'});
            return;
        }

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
        return window.IMPE_PHONE_NUMBERS.dial(valor);
    };

    const startCall = async function (payload) {
        window.IMPE_CALL_AUDIO?.unlock();
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

    // JsSIP confirma el progreso antes del siguiente sondeo de la centralita.
    // Escuchar sin sustituir los manejadores del proveedor.
    const observedOutgoingSessions = new WeakSet();
    window.setInterval(function () {
        if(!state.active || state.direction!=='outgoing')return;
        const session=window.zdrmWebPhone?.webCallSession;
        if(!session || session.direction!=='outgoing' || observedOutgoingSessions.has(session) || typeof session.on!=='function')return;
        observedOutgoingSessions.add(session);
        const token=state.callToken;
        session.on('progress',function(event){
            const code=Number(event?.response?.status_code||0);
            if(state.active && state.callToken===token && state.phase==='dialing' && [180,183].includes(code))
                publish({phase:'ringing',status:'ringing'});
        });
        const ended=function(){
            if(!state.active||state.direction!=='outgoing'||state.callToken!==token)return;
            if(transferFlow?.sourcePending())void transferFlow.tick();
            else finishCall(lastCall,state.phase==='in-progress'?'completed':'cancelled');
        };
        session.on('ended',ended);session.on('failed',ended);
    },100);

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

        // Agotar la espera no acredita un cierre PBX ni una conversación.
        hangingUp=false;
        publish({phase:'finishing',status:'ending',message:'Cierre sin confirmar. Puedes volver a comprobar el estado.'});
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
        state = emptyState();
        state.hostReady = true;
        state.phase = widgetReady ? 'ready' : 'idle';
        publish(state);
    };

    const sendTransferDtmf = async function (targetExtension, attended) {
        const ext = String(targetExtension || '');
        if (!permiteTransferir || !/^[0-9]{3,6}$/.test(ext) ||
            !state.active || state.phase !== 'in-progress') {
            publish({message:'No existe una llamada activa autorizada para transferir.'});
            return false;
        }

        const tones = (attended ? '*' : '#') + ext + '#';
        const dtmf = window.IMPE_ZADARMA_WEBRTC_DTMF;
        if (!dtmf || !dtmf.send(tones)) {
            publish({
                message:'No se pudo enviar la transferencia a ext. ' + ext +
                    ': ' + (dtmf?.error() || 'El teclado del teléfono no está disponible.') +
                    ' La llamada permanece activa.'
            });
            return false;
        }
        // El navegador aceptó la cola de tonos, pero sólo un evento
        // NOTIFY_INTERNAL transfer_from confirma el traspaso real en PBX.
        publish({
            context:Object.assign({},state.context,{transferRequest:{
                mode:attended?'CONSULTADA':'DIRECTA',extension:ext,at:Date.now()
            }}),
            message:attended
                ? 'Consultando a la extensión · ext. ' + ext +
                    '. Espera la respuesta antes de colgar.'
                : 'Solicitando transferencia directa a ext. ' +
                    ext + '. Esperando confirmación de la centralita.'
        });
        return true;
    };

    // La orden del CRM es solo una solicitud. El motor SIP vuelve a
    // consultar al servidor antes de emitir DTMF, consumiendo un token
    // de un solo uso y verificando el destino autorizado por territorio.
    const ejecutarTransferenciaAutorizada = async function (payload) {
        if (!permiteTransferir || !state.active ||
            state.phase !== 'in-progress') return;
        if(transferFlow?.sourcePending()){publish({message:'Ya hay una transferencia en curso para esta llamada.'});return;}
        const token = String(payload?.transferToken || '');
        if (!/^[a-f0-9]{48}$/.test(token) || !config.validarTransferenciaUrl) {
            publish({message:'Selecciona el destino autorizado desde Transferir.'});
            return;
        }
        const originalCall = String(state.callToken || state.pbxCallId || '');
        try {
            const body = new URLSearchParams({
                csrf_token:String(config.transferenciaCsrf || ''),
                token:token
            });
            const response = await fetch(config.validarTransferenciaUrl, {
                method:'POST', credentials:'same-origin', cache:'no-store',
                headers:{
                    'Accept':'application/json',
                    'Content-Type':'application/x-www-form-urlencoded;charset=UTF-8'
                },
                body:body.toString()
            });
            const data = await response.json();
            if (!response.ok || !data.ok) {
                throw new Error(data.mensaje || 'No se autorizó la transferencia.');
            }
            // No ejecutar un comando antiguo si mientras tanto terminó
            // la conversación y comenzó otra.
            if (!state.active || state.phase !== 'in-progress' ||
                String(state.callToken || state.pbxCallId || '') !== originalCall ||
                (data.operacion && data.operacion.referencia!==String(state.pbxCallId||state.callToken))) {
                if(data.operacion)void transferFlow?.failed(data.operacion);
                publish({message:'La llamada cambió. No se intentó transferir.'});
                return;
            }
            const submitted = await sendTransferDtmf(
                String(data.extension || ''), data.modo === 'CONSULTADA'
            );
            // Una invocación DTMF nunca confirma que el destinatario
            // recibió ni respondió. Sólo los webhooks PBX pueden hacerlo.
            if (!submitted) {
                if(data.operacion)void transferFlow?.failed(data.operacion);
                // sendTransferDtmf ya publicó la causa real; no abrir el
                // widget ni aparentar que la extensión destino está sonando.
                return;
            }
            if(data.operacion)transferFlow?.start(data.operacion);
        } catch (error) {
            publish({message:error?.message || 'No se pudo solicitar la transferencia.'});
        }
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
        } else if(action==='TRANSFER_CANDIDATE'){
            if(state.active&&state.direction==='incoming'&&state.context?.type==='SDK_INCOMING'&&
                payload.callToken===state.callToken&&Number.isInteger(payload.id)&&payload.id>0&&
                (analystSdkObserver?.isRinging?.()||analystSdkObserver?.isConnected?.())){
                publish({context:{...state.context,availabilityCandidate:payload.id}});void transferFlow?.tick();
            }
        } else if (action === 'PBX_CONFIRMED_INCOMING_END') {
            // La capa comercial consultó el detalle autorizado del PBX.
            // No emitir END a Zadarma, ni asignarlo a una sesión nueva.
            const ref=String(payload.ref||''),token=String(payload.callToken||'');
            const ownRef=String(state.pbxCallId||state.context?.transferReference||'');
            if(state.active&&state.direction==='incoming'&&
                ['recovering','finishing'].includes(state.phase)&&
                /^[A-Za-z0-9_-]{6,100}$/.test(ref)&&
                token!==''&&token===state.callToken&&ownRef===ref){
                const seconds=Number(payload.seconds);
                const duration=Number.isInteger(seconds)&&seconds>=0
                    ? seconds:currentDuration();
                answeredAtMs=0;
                retirePbxCall(ref);
                publish({active:false,phase:'incoming-finished',
                    pbxCallId:ref,status:'completed',duration,
                    context:{...state.context,pbxEnded:true,
                        pbxAnswered:Boolean(payload.connected)},
                    message:'El servicio telefónico confirmó el cierre de esta llamada.'});
                ocultarHostEntrante();
                marcarPestanaEntrante('');
            }
        } else if (action === 'HANGUP') {
            void hangup();
        } else if (action === 'COMPLETE_TRANSFER') {
            void transferFlow?.complete();
        } else if (action === 'MUTE' && state.active) {
            setMuted(Boolean(payload.muted));
        } else if (
            action === 'TRANSFER' &&
            state.active
        ) {
            void ejecutarTransferenciaAutorizada(payload);
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

    transferFlow=window.IMPE_TRANSFER_FLOW?.create({
        url:config.operacionUrl,csrf:config.transferenciaCsrf,read:()=>state,publish,
        notice:message=>publish({message}),
        finishSdk:()=>{
            const sdk=window.zdrmWebPhone;
            if(typeof sdk?.finishCall!=='function')throw Error('No se pudo entregar la llamada. Intenta nuevamente.');
            sdk.finishCall();
        },
        release:op=>{
            stopPolling();answeredAtMs=0;
            retirePbxCall(op.referencia);
            publish({active:false,phase:state.direction==='incoming'?'incoming-finished':'finished',status:['ENVIADA','ENTREGADA','SIN_RESPUESTA','SIN_CONFIRMAR'].includes(op.estado)?'transferred':'completed',context:{...state.context,transferRequest:{...state.context?.transferRequest,stage:op.estado}},message:op.estado==='ENTREGADA'?'El analista contestó la llamada.':op.estado==='ENVIADA'?'Transferencia enviada. La respuesta del analista se confirma por separado.':'Llamada finalizada.'});
        },
        external:(op,token,connected)=>{
            if(declinedInvitation(String(op.referencia||'')))return;
            answeredAtMs=connected?(state.answeredAtMs||Date.now()):0;
            publish({callToken:token,pbxCallId:'',destination:op.numero,phase:connected?'in-progress':'incoming-ringing',status:connected?'in-progress':'incoming-ringing',answeredAtMs,duration:0,context:{type:'SDK_INCOMING',sdkConnected:connected,sdkInviteId:state.context?.sdkInviteId,sdkSessionId:state.callToken,transferFrom:op.origen_extension,transferReference:op.referencia,transferOperation:op.id},message:'Recepción entregó la llamada. Ya estás hablando con la persona.'});
        },
        receiverEnded:(op,token,endedSession)=>{
            // ENTREGADA sólo la acredita la PBX para la extensión propia.
            // No procesar eventos de la sesión antigua o de otra llamada.
            if(!config.salesSdkIncoming||!state.active||state.direction!=='incoming'||
                !endedSession?.isEnded?.()||
                op.estado!=='ENTREGADA'||!state.context?.sdkConnected||
                String(token||'')!==String(state.callToken||'')||
                String(state.context?.transferReference||'')!==String(op.referencia||'')||
                String(state.context?.transferOperation||'')!==String(op.id||''))return;
            const live=window.zdrmWebPhone?.webCallSession;
            if(live&&live!==endedSession&&!live.isEnded?.())return;
            const ref=String(op.referencia||''),duration=currentDuration();
            answeredAtMs=0;retirePbxCall(ref);
            publish({active:false,phase:'incoming-finished',status:'ended-unconfirmed',
                pbxCallId:ref,duration,
                context:{...state.context,sdkEnded:true,transferPendingReceiver:false},
                message:'La llamada terminó en el navegador. Falta la confirmación del servicio telefónico.'});
            ocultarHostEntrante();marcarPestanaEntrante('');
        },
        consultEnded:op=>{
            if(!state.active)return;
            if(op.modo==='DIRECTA'){
                const answered=Boolean(state.context?.sdkConnected||state.context?.pbxAnswered||answeredAtMs>0);
                const session=window.zdrmWebPhone?.webCallSession;
                // El END de la consulta DIRECTA se comprobó en transferFlow
                // contra la RTCSession del receptor. Si Zadarma ya acreditó
                // ENTREGADA y no existe otro diálogo SIP vivo, finalizar
                // inmediatamente el teléfono local. El END PBX y las
                // grabaciones continúan conciliándose por separado.
                const ownDelivered=Boolean(config.salesSdkIncoming &&
                    op.estado==='ENTREGADA' && answered &&
                    state.direction==='incoming' &&
                    String(state.context?.transferOperation||'')===String(op.id||'') &&
                    String(state.context?.transferReference||'')===String(op.referencia||'') &&
                    (!session || session.isEnded?.()));
                if(ownDelivered){
                    const ref=String(op.referencia||''),duration=currentDuration();
                    answeredAtMs=0;
                    retirePbxCall(ref);
                    publish({active:false,phase:'incoming-finished',status:'ended-unconfirmed',
                        pbxCallId:ref,duration,
                        context:{...state.context,sdkEnded:true,transferPendingReceiver:false},
                        message:'La llamada terminó en el navegador. El historial se actualizará por separado.'});
                    ocultarHostEntrante();marcarPestanaEntrante('');
                    return;
                }
                publish({active:true,phase:answered?'recovering':'finishing',status:answered?'recovering':'ending',context:{...state.context,transferPendingReceiver:false,transferReference:op.referencia},message:answered?'El audio del navegador se interrumpió. Comprobando si la llamada sigue activa en la centralita.':'Intento sin respuesta confirmada. Comprobando cierre en la centralita.'});
                void pollIncoming();return;
            }
            answeredAtMs=0;publish({active:false,phase:'incoming-finished',status:'consultation-ended',duration:0,message:'Consulta de transferencia finalizada.'});
        }
    })||null;
    window.setInterval(()=>void transferFlow?.tick(),1000);

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

    const legacyBusy = function (lease) {
        const now = Date.now(), last = Number(lease?.time || 0);
        if (last > 0 && last <= now + 10000 && now - last < 30000) return true;
        // Conservar evidencia que estaba presente ANTES de publicar nuestro
        // estado loading: evita confundir este host con una pestaña anterior.
        const candidates = [previousState];
        try { candidates.push(JSON.parse(localStorage.getItem(stateKey) || 'null')); }
        catch (_) { /* Estado anterior dañado. */ }
        return candidates.some(previous => {
            const at = Number(previous?.updatedAt || 0);
            if (Number(previous?.userId) !== userId || at <= 0 || at > now + 10000) return false;
            return (previous.active && now - at < 600000) ||
                (previous.hostReady && now - at < 30000);
        });
    };
    const reconcileLegacy = function () {
        try {
            const key = 'impe:telephony:shell-owner:' + userId;
            const raw = localStorage.getItem(key);
            if (!raw) return true;
            let legacy;
            try { legacy = JSON.parse(raw); } catch (_) { legacy = null; }
            if (!legacy || legacy.version === 2) return true;
            if (legacyBusy(legacy)) return false;
            if (localStorage.getItem(key) !== raw) return false;
            localStorage.removeItem(key);
            return true;
        } catch (_) { return false; }
    };
    let transportLockRequested = false;
    const claimTransport = function () {
        if (ownsTransport || transportLockRequested || !navigator.locks?.request) return;
        transportLockRequested = true;
        publish({message:'Comprobando la disponibilidad del teléfono en este navegador…'});
        void navigator.locks.request('impe:telephony:shell-owner:' + userId, async () => {
            if (!reconcileLegacy()) {
                publish({phase:'error',
                    message:'Comprobando una conexión anterior del teléfono. Si ya se cerró, se activará automáticamente.'});
                return;
            }
            ownsTransport = true;
            const holding = new Promise(resolve => {releaseTransport = resolve;});
            try { void checkConnection(); await holding; }
            finally { ownsTransport = false; releaseTransport = null; }
        }).catch(() => publish({phase:'error',message:'No se pudo reservar el teléfono.'}))
          .finally(() => { transportLockRequested = false; });
    };
    if (ownsTransport) void checkConnection();
    else if (navigator.locks?.request) claimTransport();
    else publish({phase:'error',message:'Abre el teléfono desde el CRM en un navegador compatible.'});
    // Una reserva antigua fresca se revisa sola: no exige F12 ni recargar Opera.
    setInterval(() => { if (!ownsTransport) claimTransport(); }, 10000);
    addEventListener('pagehide',event => {if (!event.persisted) releaseTransport?.();});

    setInterval(function () {
        const session=window.zdrmWebPhone?.webCallSession;
        // Sólo el transporte WebRTC real puede acreditar recuperación de audio.
        if(state.active&&state.phase==='recovering'&&navigator.onLine!==false&&session?.isEstablished?.()&&
            (session.connection?.connectionState==='connected'||['connected','completed'].includes(session.connection?.iceConnectionState))){
            widgetReady=true;publish({phase:'in-progress',status:'in-progress',context:{...state.context,sdkConnected:true},message:'Audio del navegador recuperado.'});
            return;
        }
        void checkConnection();
        publish({});
    }, 1500);

    // La pérdida de red no equivale a un cierre PBX y nunca desmonta una llamada activa.
    addEventListener('offline',()=>{publish({phase:state.active?'recovering':'error',status:'recovering',message:'Sin conexión de red. La llamada puede seguir activa en la centralita.'});});
    addEventListener('online',()=>{startupRetryAt=0;void checkConnection();if(state.active)void pollIncoming();});
    addEventListener('focus',()=>void checkConnection());
    addEventListener('pageshow',()=>void checkConnection());
    document.addEventListener('visibilitychange',()=>void checkConnection());

    setInterval(render, 500);
    render();
})();
