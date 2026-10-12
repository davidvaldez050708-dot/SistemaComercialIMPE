/* Shell persistente para PHP + Zadarma; no utiliza ventanas emergentes.
 * El SDK vive en un iframe que jamás se reemplaza al navegar entre módulos.
 * Los comandos/estados de los módulos siguen usando BroadcastChannel +
 * localStorage, como el teléfono existente.
 */
(function () {
    'use strict';
    const cfg = window.IMPE_PHONE_SHELL_CONFIG || {};
    const userId = Number(cfg.userId || 0);
    if (!userId || !cfg.hostUrl) return;

    const crm = document.getElementById('impeCrmFrame');
    const call = document.querySelector('[data-shell-call]');
    const hostRegion = document.querySelector('[data-shell-host-region]');
    const status = document.querySelector('[data-shell-status]');
    const statusText = document.querySelector('[data-shell-status-text]');
    const retry = document.querySelector('[data-shell-retry]');
    const restore = document.querySelector('[data-shell-restore]');
    const callTitle = document.querySelector('[data-shell-call-title]');
    const callStatus = document.querySelector('[data-shell-call-status]');
    const callHelp = document.querySelector('[data-shell-call-help]');
    const incomingPanel = document.querySelector('[data-incoming-panel]');
    const incomingNumber = document.querySelector('[data-incoming-number]');
    const incomingState = document.querySelector('[data-incoming-state]');
    const incomingTitle = document.querySelector('[data-incoming-title]');
    const incomingEyebrow = document.querySelector('[data-incoming-eyebrow]');
    const incomingTimer = document.querySelector('[data-incoming-timer]');
    const answerButton = document.querySelector('[data-incoming-answer]');
    const declineButton = document.querySelector('[data-incoming-decline]');
    const muteButton = document.querySelector('[data-incoming-mute]');
    const hangupButton = document.querySelector('[data-incoming-hangup]');
    const providerButton = document.querySelector('[data-incoming-provider]');
    const notice = document.querySelector('[data-incoming-notice]');
    const stateKey = 'impe:telephony:state:' + userId;
    const commandKey = 'impe:telephony:command:' + userId;
    const leaseKey = 'impe:telephony:shell-owner:' + userId;
    const channelName = 'impe-telephony-' + userId;
    const owner = 'shell-' + Date.now().toString(36) + '-' +
        Math.random().toString(36).slice(2);
    const channel = typeof BroadcastChannel === 'function'
        ? new BroadcastChannel(channelName) : null;
    let hostFrame = null;
    let minimized = false;
    let actionNotice = '';
    let forceNativeControls = false;
    let currentState = null;
    let lastHeartbeat = 0;
    let mountFailed = '';
    let hostStartedAt = 0;
    let owned = false;
    let autoRetries = 0;
    let lastRecoveryAttempt = 0;

    // El host debe existir aunque los controles estén minimizados.
    // display:none sobre el contenedor apagaba algunos SDK.
    call.removeAttribute('hidden');

    const getLease = function () {
        try { return JSON.parse(localStorage.getItem(leaseKey) || 'null'); }
        catch (e) { return null; }
    };

    const claimLease = function () {
        const lease = getLease();
        if (lease && lease.id !== owner && Date.now() - Number(lease.time || 0) < 9000) {
            return false;
        }
        try {
            localStorage.setItem(leaseKey, JSON.stringify({id:owner,time:Date.now()}));
            const confirmed = getLease();
            owned = confirmed?.id === owner;
            return owned;
        } catch (e) {
            // Sin almacenamiento no existe coordinación segura entre pestañas.
            mountFailed = 'Activa el almacenamiento del navegador para conectar el teléfono.';
            return false;
        }
    };

    const releaseLease = function () {
        if (!owned) return;
        try {
            if (getLease()?.id === owner) localStorage.removeItem(leaseKey);
        } catch (e) { /* navegador restringido */ }
        owned = false;
    };

    const ensureHost = function () {
        if (hostFrame) return hostFrame.contentWindow;
        if (!claimLease()) {
            mountFailed = mountFailed ||
                'La extensión ya se está usando en otra pestaña del CRM. Ciérrala antes de conectar aquí.';
            render();
            throw new Error(mountFailed);
        }
        mountFailed = '';
        hostStartedAt = Date.now();
        const frame = document.createElement('iframe');
        frame.title = 'Motor telefónico Zadarma integrado';
        frame.allow = 'microphone; autoplay';
        frame.setAttribute('data-shell-engine', '1');
        frame.src = String(cfg.hostUrl);
        frame.addEventListener('error', function () {
            mountFailed = 'No se pudo cargar el motor telefónico. Intenta conectar de nuevo.';
            render();
        });
        hostRegion.appendChild(frame);
        hostFrame = frame;
        render();
        return frame.contentWindow;
    };

    // Acciones de las vistas PHP: el marcador pertenece al documento padre y
    // sobrevive a los cambios de Inicio, Seguimiento, Ventas o Convocatorias.
    const crmPhone = function () {
        try { return crm?.contentWindow?.IMPE_TELEPHONY_PERSISTENT || null; }
        catch (error) { return null; }
    };
    const dialer = window.IMPE_TELEPHONY_DIALER?.create({
        root: document.body,
        extension: cfg.extension,
        getApi: crmPhone,
        getState: function () { return currentState; },
        hasOutgoingRole: function () { return cfg.canCallOut === true; },
        showIncoming: function () {
            minimized = false;
            render();
        }
    }) || null;
    const openDialer = function (options) {
        if (!dialer) return false;
        dialer.open(options || {});
        return true;
    };

    // Es accesible sólo desde la vista PHP hija del mismo dominio.
    window.IMPE_TELEPHONY_SHELL = Object.freeze({
        userId: userId,
        ensureHost: ensureHost,
        openDialer: openDialer,
        isDialerManagingCall: function () {
            return Boolean(dialer?.isManagingCall());
        },
        isEmbedded: true
    });

    const freshState = function (value) {
        return value && Number(value.userId) === userId &&
            Number(value.updatedAt) > 0 &&
            Date.now() - Number(value.updatedAt) < 5500;
    };

    const updateState = function (value) {
        if (!freshState(value)) return;
        currentState = value;
        lastHeartbeat = Date.now();
        dialer?.sync(value);
        render();
    };

    const providerBridge = function () {
        if (!hostFrame) return null;
        try {
            return hostFrame.contentWindow.IMPE_ZADARMA_INCOMING || null;
        } catch (_) { return null; }
    };
    const sendPhoneCommand = function (action, payload) {
        const message = {
            type: 'COMMAND', action: action, payload: payload || {},
            id: 'incoming-' + Date.now() + '-' + Math.random().toString(36).slice(2)
        };
        try { localStorage.setItem(commandKey, JSON.stringify(message)); }
        catch (_) { /* BroadcastChannel permite operar con el host */ }
        channel?.postMessage(message);
    };
    const formatDuration = function (seconds) {
        const total=Math.max(0,Number(seconds)||0);
        return String(Math.floor(total/60)).padStart(2,'0') + ':' +
            String(Math.floor(total%60)).padStart(2,'0');
    };

    const render = function () {
        const state = freshState(currentState) ? currentState : null;
        const incoming = Boolean(state?.active && state.direction === 'incoming');
        const ongoing = Boolean(state?.active);
        if (!ongoing) {
            forceNativeControls = false;
            minimized = false;
            actionNotice = '';
        }
        const ready = Boolean(state && state.hostReady &&
            ['ready', 'prepared', 'incoming-ringing', 'in-progress',
                'dialing', 'ringing', 'finished', 'incoming-finished'].includes(String(state.phase)));
        const failed = mountFailed || (state?.phase === 'error' ?
            (state.message || 'No fue posible conectar con Zadarma.') : '');
        const otherOwner = !owned && getLease() &&
            Date.now() - Number(getLease()?.time || 0) < 9000;

        status.hidden = ready && !failed;
        statusText.textContent = failed ||
            (otherOwner ? 'El teléfono está conectado desde otra pestaña.' :
            hostFrame ? 'Preparando recepción de la extensión ' + cfg.extension + '…' :
            'El teléfono está disponible para conectarse.');
        retry.hidden = ready && !failed || Boolean(otherOwner && !owned);
        if (!hostFrame && !cfg.autoReception) status.hidden = true;

        if (hostFrame && !ready && !failed &&
            Date.now() - hostStartedAt > 16000) {
            status.hidden = false;
            statusText.textContent = 'WebRTC todavía no confirma que esté listo.';
            retry.hidden = false;
        }

        // Una sola tarjeta con el diseño del CRM. El widget se conserva
        // montado fuera de pantalla: audio/WebRTC no se desmontan.
        const nativeVisible = Boolean(state?.hostReady) &&
            ongoing && forceNativeControls && !minimized;
        const customVisible = incoming && !forceNativeControls && !minimized;
        call.classList.toggle('is-visible', nativeVisible);
        if (incomingPanel) incomingPanel.hidden = !customVisible;
        restore.hidden = !incoming || !minimized;
        if (incoming && incomingPanel) {
            const ringing=state.phase === 'incoming-ringing';
            const bridgeReady=Boolean(providerBridge()?.canAnswer());
            incomingNumber.textContent = String(state.destination || 'Número desconocido');
            incomingTitle.textContent = ringing ? 'Llamada entrante' : 'En conversación';
            incomingEyebrow.textContent = ringing ? 'RECEPCIÓN TELEFÓNICA' : 'LLAMADA EN CURSO';
            incomingState.textContent = ringing ? 'Llamada entrante a la extensión ' + cfg.extension
                : 'Conversación en curso';
            incomingTimer.textContent = formatDuration(state.duration);
            answerButton.hidden = !ringing;
            declineButton.hidden = !ringing;
            answerButton.disabled = !bridgeReady;
            declineButton.disabled = !bridgeReady;
            muteButton.hidden = ringing;
            hangupButton.hidden = ringing;
            hangupButton.disabled = !providerBridge()?.canHangup();
            muteButton.textContent = state.muted ? 'Activar micrófono' : 'Silenciar';
            notice.hidden = !actionNotice && bridgeReady;
            notice.textContent = actionNotice ||
                'El teléfono aún no confirma que sus botones puedan utilizarse desde el CRM.';
            providerButton.hidden = !state.hostReady;
        }
        callTitle.textContent = incoming ? 'Llamada entrante' :
            ongoing ? 'Llamada en curso' : 'Teléfono institucional';
        callStatus.textContent = incoming ?
            'Desde ' + String(state.destination || 'Número desconocido') :
            'Extensión ' + cfg.extension;
        if (callHelp) {
            callHelp.textContent = incoming
                ? 'Utiliza Contestar en el teléfono Zadarma. Si no aparece, la centralita detectó la llamada, pero WebRTC no confirma el timbrado.'
                : 'Teléfono de la extensión ' + cfg.extension + '.';
        }

        // Sólo se hace visible la interfaz oficial si la llamada es entrante.
        // Para salientes, el panel del CRM sigue teniendo sus controles.
        if (hostFrame) {
            hostFrame.dataset.callDirection = incoming ? 'incoming' : 'outgoing';
        }
        // El aviso de conexión y "Mostrar llamada" son ambos flotantes:
        // nunca apilarlos uno encima del otro.
        status.classList.toggle('is-raised', !restore.hidden);
    };

    window.addEventListener('message', function (event) {
        if (event.origin !== window.location.origin ||
            !hostFrame || event.source !== hostFrame.contentWindow ||
            event.data?.type !== 'IMPE_TELEPHONY_NATIVE_CONTROLS' ||
            Number(event.data.userId) !== userId) {
            return;
        }
        // Sólo mostrar controles oficiales si el usuario lo solicita.
        forceNativeControls = true;
        minimized = false;
        render();
    });

    const readState = function () {
        try { updateState(JSON.parse(localStorage.getItem(stateKey) || 'null')); }
        catch (e) { /* sin estado anterior */ }
    };
    channel?.addEventListener('message', function (event) {
        if (event.data?.type === 'STATE') updateState(event.data.state);
    });
    window.addEventListener('storage', function (event) {
        if (event.key === stateKey && event.newValue) readState();
    });

    document.querySelector('[data-shell-minimize]')?.addEventListener('click', function () {
        forceNativeControls = false;
        minimized = true; render();
    });
    document.querySelector('[data-incoming-minimize]')?.addEventListener('click', function () {
        minimized = true; render();
    });
    answerButton?.addEventListener('click', function () {
        if (!freshState(currentState) || !currentState.active ||
            currentState.phase !== 'incoming-ringing') return;
        if (!providerBridge()?.answer()) {
            actionNotice = providerBridge()?.error() ||
                'No se identificó un control seguro para contestar.';
        } else {
            actionNotice = 'Contestando… espera la confirmación de Zadarma.';
        }
        render();
    });
    declineButton?.addEventListener('click', function () {
        if (!freshState(currentState) || !currentState.active ||
            currentState.phase !== 'incoming-ringing') return;
        if (!providerBridge()?.decline()) {
            actionNotice = providerBridge()?.error() ||
                'No se identificó un control seguro para rechazar.';
        } else {
            actionNotice = 'Rechazando llamada…';
        }
        render();
    });
    muteButton?.addEventListener('click', function () {
        if (!freshState(currentState) || currentState.phase !== 'in-progress') return;
        sendPhoneCommand('MUTE', {muted:!Boolean(currentState.muted)});
    });
    hangupButton?.addEventListener('click', function () {
        if (!freshState(currentState) || currentState.phase !== 'in-progress') return;
        if (!providerBridge()?.hangup()) {
            actionNotice = providerBridge()?.error() ||
                'No se identificó un control seguro para finalizar.';
        } else {
            actionNotice = 'Finalizando llamada…';
        }
        render();
    });
    providerButton?.addEventListener('click', function () {
        if (!freshState(currentState) || !currentState.active) return;
        actionNotice = '';
        forceNativeControls = true;
        minimized = false;
        render();
    });
    restore?.addEventListener('click', function () {
        minimized = false; render();
    });
    retry?.addEventListener('click', function () {
        if (hostFrame) {
            // No recargar automáticamente un contexto con llamada activa.
            if (freshState(currentState) && currentState.active) return;
            hostFrame.remove();
            hostFrame = null;
            currentState = null;
            mountFailed = '';
            releaseLease();
        }
        try { ensureHost(); } catch (e) { render(); }
    });

    // Si una petición expira, el login no debe quedar atrapado dentro del
    // iframe: salir del shell desmonta también el contexto WebRTC.
    crm?.addEventListener('load', function () {
        try {
            const location = crm.contentWindow.location;
            const url = new URL(location.href);
            if (url.origin !== window.location.origin) return;
            const controller = url.searchParams.get('controller') || '';
            const action = url.searchParams.get('action') || '';
            if (controller === 'login' && action === 'mostrarLogin') {
                window.location.replace(String(cfg.loginUrl));
                return;
            }
            const heading = crm.contentDocument?.title;
            if (heading) document.title = heading + ' · Sistema Comercial';
        } catch (e) {
            // No inspeccionar documentos de origen externo.
        }
    });

    // Mantener única conexión dentro del mismo perfil del navegador.
    window.setInterval(function () {
        if (autoEnabled && hostFrame &&
            !freshState(currentState) && !mountFailed &&
            Date.now() - hostStartedAt > 22000 &&
            Date.now() - lastRecoveryAttempt > 30000 &&
            autoRetries < 2) {
            // El indicador apagado no debe quedar en un estado verde falso.
            // Volver a montar sólo cuando la conexión NO tiene latidos.
            autoRetries += 1;
            lastRecoveryAttempt = Date.now();
            hostFrame.remove();
            hostFrame = null;
            currentState = null;
            releaseLease();
            try { ensureHost(); } catch (error) { mountFailed = error.message; }
        }
        if (owned) {
            const lease = getLease();
            if (lease?.id !== owner) {
                owned = false;
                mountFailed = 'Otra pestaña asumió el teléfono. Verifica la conexión.';
                render();
            } else {
                try {
                    localStorage.setItem(leaseKey,
                        JSON.stringify({id:owner,time:Date.now()}));
                } catch (e) { /* Sin almacenamiento, avisar al usuario. */ }
            }
        }
        render();
    }, 3000);
    window.addEventListener('beforeunload', function (event) {
        if (freshState(currentState) && currentState.active) {
            // Navegar entre módulos internos no descarga este documento.
            // Sólo advertir si intentan cerrar o recargar la capa persistente.
            event.preventDefault();
            event.returnValue = '';
        }
    });
    window.addEventListener('pagehide', function () {
        releaseLease();
        channel?.close();
    });

    readState();
    let autoEnabled = cfg.autoReception === true;
    if (autoEnabled) {
        try {
            autoEnabled = localStorage.getItem(
                'impe:telephony:auto-reception:' + userId
            ) !== '0';
        } catch (error) { /* usar valor predeterminado */ }
    }
    if (autoEnabled) {
        // La autorización del navegador para micrófono/audio podría requerir
        // un clic. En ese caso se deja visible Conectar teléfono.
        try { ensureHost(); }
        catch (e) { mountFailed = e.message; }
    }
    render();
})();
