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

    // Es accesible sólo desde la vista PHP hija del mismo dominio.
    window.IMPE_TELEPHONY_SHELL = Object.freeze({
        userId: userId,
        ensureHost: ensureHost,
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
        render();
    };

    const render = function () {
        const state = freshState(currentState) ? currentState : null;
        const incoming = Boolean(state?.active && state.direction === 'incoming');
        const ongoing = Boolean(state?.active);
        const ready = Boolean(state && state.hostReady &&
            ['ready', 'prepared', 'incoming-ringing', 'in-progress',
                'dialing', 'ringing', 'finished'].includes(String(state.phase)));
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

        // Las salientes conservan el panel telefónico del CRM; abrir la UI
        // oficial de Zadarma sólo es necesario para aceptar entrantes.
        const showCall = incoming && !minimized;
        call.classList.toggle('is-visible', showCall);
        restore.hidden = !incoming || showCall;
        callTitle.textContent = incoming ? 'Llamada entrante' :
            ongoing ? 'Llamada en curso' : 'Teléfono institucional';
        callStatus.textContent = incoming ?
            'Desde ' + String(state.destination || 'Número desconocido') :
            'Extensión ' + cfg.extension;

        // Sólo se hace visible la interfaz oficial si la llamada es entrante.
        // Para salientes, el panel del CRM sigue teniendo sus controles.
        if (hostFrame) {
            hostFrame.dataset.callDirection = incoming ? 'incoming' : 'outgoing';
        }
    };

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
        minimized = true; render();
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
