/* Shell persistente para PHP + Zadarma; no utiliza ventanas emergentes.
 * El SDK vive en un iframe que jamás se reemplaza al navegar entre módulos.
 * Los comandos/estados de los módulos siguen usando BroadcastChannel +
 * localStorage, como el teléfono existente.
 */
(function () {
    'use strict';
    const cfg = window.IMPE_PHONE_SHELL_CONFIG || {};
    const userId = Number(cfg.userId || 0);
    if (!userId || !cfg.hostUrl || window.IMPE_TELEPHONY_SHELL) return;

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
    const incomingOrigin = document.querySelector('[data-incoming-origin]');
    const incomingOriginLabel = document.querySelector('[data-incoming-origin-label]');
    const incomingTitle = document.querySelector('[data-incoming-title]');
    const incomingEyebrow = document.querySelector('[data-incoming-eyebrow]');
    const incomingTimer = document.querySelector('[data-incoming-timer]');
    const answerButton = document.querySelector('[data-incoming-answer]');
    const declineButton = document.querySelector('[data-incoming-decline]');
    const muteButton = document.querySelector('[data-incoming-mute]');
    const hangupButton = document.querySelector('[data-incoming-hangup]');
    const compactAnswer = document.querySelector('[data-incoming-answer-compact]');
    const compactDecline = document.querySelector('[data-incoming-decline-compact]');
    const compactMute = document.querySelector('[data-incoming-mute-compact]');
    const compactHangup = document.querySelector('[data-incoming-hangup-compact]');
    const incomingHeader = document.querySelector('[data-incoming-drag-handle]');
    const incomingCompactTimer = document.querySelector('[data-incoming-compact-timer]');
    const notice = document.querySelector('[data-incoming-notice]');
    const closeFinished = document.querySelector('[data-incoming-close-finished]');
    const callbackSaved=new Set();
    let callbackResultOwner=0,callbackPending=null;
    let resultFormOwner = null;
    const callbackPendingKey='impe:callback-pending:'+userId;
    try{const saved=JSON.parse(sessionStorage.getItem(callbackPendingKey)||'null');if(Number(saved?.id)>0 && Date.now()-Number(saved.at)<86400000)callbackPending=saved;}catch(_){}
    const rememberCallback=()=>{try{if(callbackPending)sessionStorage.setItem(callbackPendingKey,JSON.stringify(callbackPending));else sessionStorage.removeItem(callbackPendingKey);}catch(_){}};
    const transferToggle = document.querySelector('[data-shell-transfer-toggle]');
    const transferPane = document.querySelector('[data-shell-transfer-panel]');
    const captureToggle = document.querySelector('[data-shell-capture-toggle]');
    const capturePane = document.querySelector('[data-shell-capture-panel]');
    const analystResultNotice = document.querySelector('[data-analyst-call-result]');
    const analystResultButton = document.querySelector('[data-analyst-register-call]');
    const analystResultDismiss = document.querySelector('[data-analyst-dismiss-call]');
    const analystResultKey = 'impe:analyst-incoming-pending:' + userId;
    let analystPendingCall = null;
    if (cfg.analystIncomingResults) {
        try {
            analystPendingCall = JSON.parse(sessionStorage.getItem(analystResultKey) || 'null');
            if (!analystPendingCall || typeof analystPendingCall.id !== 'string' ||
                !analystPendingCall.id || Date.now() - Number(analystPendingCall.at || 0) > 86400000) {
                analystPendingCall = null;
                sessionStorage.removeItem(analystResultKey);
            }
        } catch (_) { analystPendingCall = null; }
    }
    const stateKey = 'impe:telephony:state:' + userId;
    const commandKey = 'impe:telephony:command:' + userId;
    const leaseKey = 'impe:telephony:shell-owner:' + userId;
    const channelName = 'impe-telephony-' + userId;
    const owner = 'shell-' + Date.now().toString(36) + '-' +
        Math.random().toString(36).slice(2);
    const channel = typeof BroadcastChannel === 'function'
        ? new BroadcastChannel(channelName) : null;
    const openedAt = Date.now();
    let hostFrame = null;
    let minimized = false;
    let actionNotice = '';
    // El comando se ejecuta sin esperar la siguiente consulta PBX.
    // Nunca interpretar la invocación SDK como confirmación de conexión.
    let pendingIncomingAction = null;
    const incomingCallId = value =>
        String(value?.pbxCallId || value?.callToken || '').trim();
    const pendingFor = state => {
        if (!pendingIncomingAction || !state?.active ||
            state.direction !== 'incoming' ||
            pendingIncomingAction.callId !== incomingCallId(state)) return '';
        // Rehabilitar el modal cuando la PBX no confirmó el comando.
        if (pendingIncomingAction.action !== 'decline' && Date.now() - pendingIncomingAction.at > 4500) {
            pendingIncomingAction = null;
            return '';
        }
        return pendingIncomingAction.action;
    };
    let incomingCompact = false;
    let incomingDrag = null;
    let centerNextCall = true;
    const viewportSize=()=>{
        const size={width:Math.min(window.innerWidth,window.visualViewport?.width||window.innerWidth),height:Math.min(window.innerHeight,window.visualViewport?.height||window.innerHeight)};
        if(incomingPanel)incomingPanel.style.maxHeight=Math.max(100,size.height-16)+'px';
        return size;
    };
    const centerIncoming = function () {
        if (!incomingPanel || incomingPanel.hidden || !centerNextCall) return;
        const view=viewportSize(),rect = incomingPanel.getBoundingClientRect();
        incomingPanel.style.left = Math.max(8,(view.width-rect.width)/2) + 'px';
        incomingPanel.style.top = Math.max(8,(view.height-rect.height)/2) + 'px';
        incomingPanel.style.right = 'auto';
        incomingPanel.style.bottom = 'auto';
        centerNextCall = false;
    };
    const incomingPositionKey = 'impe:telephony:incoming-position:' + userId;
    const incomingViewKey = 'impe:telephony:incoming-compact:' + userId;
    try { incomingCompact = localStorage.getItem(incomingViewKey) === '1'; }
    catch (_) {}
    // La posición y minimización se manejan igual que en Seguimiento.
    try {
        const saved = JSON.parse(localStorage.getItem(incomingPositionKey) || 'null');
        if (saved && Number.isFinite(Number(saved.left)) &&
            Number.isFinite(Number(saved.top))) {
            incomingPanel.style.left = Number(saved.left) + 'px';
            incomingPanel.style.top = Number(saved.top) + 'px';
            incomingPanel.style.right = 'auto';
            incomingPanel.style.bottom = 'auto';
        }
    } catch (_) {}
    const clampIncoming = function () {
        if (!incomingPanel || incomingPanel.hidden || !incomingPanel.style.left) return;
        const view=viewportSize(),rect = incomingPanel.getBoundingClientRect();
        const maxLeft = Math.max(8, view.width - rect.width - 8);
        const maxTop = Math.max(8, view.height - rect.height - 8);
        incomingPanel.style.left = Math.max(8,Math.min(maxLeft,
            Number.parseFloat(incomingPanel.style.left) || 8)) + 'px';
        incomingPanel.style.top = Math.max(8,Math.min(maxTop,
            Number.parseFloat(incomingPanel.style.top) || 8)) + 'px';
    };
    const toggleIncomingCompact = function () {
        incomingCompact = !incomingCompact;
        try { localStorage.setItem(incomingViewKey,incomingCompact?'1':'0'); }
        catch (_) {}
        render();
        clampIncoming();
    };
    let forceNativeControls = false;
    let nativeTransferOpen = false;
    // Marcación iniciada desde el teclado: visualización inmediata mientras
    // el único host WebRTC confirma el estado real de la saliente.
    let outgoingAttempt = null;
    let pendingOutgoingHangup = null;
    let recentFinishedOutgoing = null;
    // La llamada entrante finalizada sólo permanece en esta sesión.
    let recentFinishedIncoming = null;
    const isManualOutgoing = value =>
        ['TELEPHONY_MANUAL','MARKETING_CALLBACK','DIALER','DEVOLUCION'].includes(
            String(value?.context?.type || '').toUpperCase()
        );
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
        singleCallPanel: true,
        onCallRequested: function (call) {
            outgoingAttempt = {number:String(call?.number || ''),at:Date.now()};
            incomingCompact = false;
            centerNextCall = true;
            render();
        },
        onCallFailed: function () {
            outgoingAttempt = null;
            pendingOutgoingHangup = null;
            render();
        },
        showOngoing: function () {
            incomingCompact = false;
            render();
        },
        showIncoming: function () {
            incomingCompact = false;
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
        isPhoneReady: function () {return Boolean(freshState(currentState) && currentState.hostReady);},
        getCallState: function () {return freshState(currentState) ? JSON.parse(JSON.stringify(currentState)) : null;},
        getReceptionStatus: function () {
            const assigned = cfg.canReceive === true && /^[0-9]{3,6}$/.test(String(cfg.extension || ''));
            return {assigned, extension:assigned ? String(cfg.extension) : '',
                ready:assigned && Boolean(freshState(currentState) && currentState.hostReady)};
        },
        unlockAudio: function () {
            try {hostFrame?.contentWindow.IMPE_CALL_AUDIO?.unlock();}catch(_){}
        },
        showProviderControls: function () {
            // SOPORTE_RECEPTOR_TRANSFER_20261015
            if (!hostFrame || !freshState(currentState) ||
                !currentState.hostReady || !currentState.active) return false;
            nativeTransferOpen = true;
            forceNativeControls = true;
            minimized = false;
            sendPhoneCommand('SHOW_CONTROLS');
            render();
            return true;
        },
        getPhoneStatus: function () {
            return {active:Boolean(currentState?.active),ready:Boolean(freshState(currentState)&&currentState.hostReady),
                pending:Boolean(callbackPending||window.IMPE_CALL_CLOSURE?.hasPending()||window.IMPE_SALES_ATTENTION?.hasPending()||document.body.classList.contains('impe-sdk-result-in-modal'))};
        },
        openPendingResult: function () {
            if(window.IMPE_SALES_ATTENTION?.hasPending()){window.IMPE_SALES_ATTENTION.openPending();return;}
            if(callbackPending&&!currentState?.active){
                if(crm)crm.src=cfg.callbackUrl+'&intento_id='+callbackPending.id;render();return;
            }
            const btn=document.querySelector('[data-incoming-register-finished]');
            if(btn&&!btn.hidden)btn.click();else window.IMPE_CALL_CLOSURE?.showPending();
        },
        beginCallbackResult: function (id) {
            if(currentState?.active||!Number.isSafeInteger(id)||id<=0)return false;
            callbackResultOwner=id;if(!callbackPending)callbackPending={id,at:Date.now()};rememberCallback();render();return true;
        },
        endCallbackResult: function (id) {if(callbackResultOwner===id){callbackResultOwner=0;render();}},
        minimizeCallbackResult: function (id) {return this.beginCallbackResult(id);},
        beginCallResult: function (token, refs) {
            if (currentState?.active || !/^[a-zA-Z0-9_-]{12,100}$/.test(String(token))) return false;
            const valid = (Array.isArray(refs) ? refs : []).filter(ref => /^[a-zA-Z0-9_-]{3,100}$/.test(String(ref)));
            if (!valid.length) return false;
            resultFormOwner = {token, refs:valid};
            render();
            return true;
        },
        endCallResult: function (token) {
            if (resultFormOwner?.token !== token) return;
            resultFormOwner = null;
            render();
        },
        dismissFinishedCall: function (ref) {
            if(currentState?.active)return false;
            const matches=s=>[s?.pbxCallId,s?.callToken,s?.finalMetadata?.pbx_call_id,s?.context?.transferReference].includes(ref);
            if(recentFinishedIncoming&&matches(recentFinishedIncoming.state))recentFinishedIncoming=null;
            if(recentFinishedOutgoing&&matches(recentFinishedOutgoing.state))recentFinishedOutgoing=null;
            if(resultFormOwner?.refs.includes(ref))resultFormOwner=null;
            render();return true;
        },
        isResultFormOpen: function () { return Boolean(resultFormOwner) && !currentState?.active; },

        getActiveCall: function () {
            if (!freshState(currentState) || !currentState.active ||
                currentState.phase !== 'in-progress') return null;
            return {
                id:incomingCallId(currentState),
                aliases:[currentState.pbxCallId,currentState.callToken].filter(Boolean),
                direction:String(currentState.direction || ''),
                phone:String(currentState.destination || ''),
                extension:String(cfg.extension || '')
                ,transferRequest:currentState.context?.transferRequest||null
            };
        },
        requestAuthorizedTransfer: function (token) {
            if (!cfg.canTransfer || !this.getActiveCall() ||
                !/^[a-f0-9]{48}$/.test(String(token || ''))) return false;
            sendPhoneCommand('TRANSFER', {transferToken: String(token)});
            return true;
        },
        openDialer: openDialer,
        isDialerManagingCall: function () {
            return Boolean(dialer?.isManagingCall());
        },
        isEmbedded: true
    });

    const freshState = function (value) {
        return value && Number(value.userId) === userId &&
            Number(value.updatedAt) >= openedAt - 1000 &&
            Number(value.updatedAt) <= Date.now() + 1000 &&
            Date.now() - Number(value.updatedAt) < 5500;
    };

    const updateState = function (value) {
        if (!freshState(value)) return;
        const startsCall = value.active && !currentState?.active;
        if(value.context?.type==='DEVOLUCION'&&!value.active&&['finished','incoming-finished'].includes(value.phase)&&Number(value.context.callbackAttempt)>0&&!callbackSaved.has(Number(value.context.callbackAttempt))){
            callbackPending={id:Number(value.context.callbackAttempt),at:Date.now(),callToken:value.callToken};rememberCallback();
        }
        if(value.active){
            callbackResultOwner=0;
            if(resultFormOwner){
                resultFormOwner=null;
                crm?.contentWindow?.postMessage({type:'IMPE_CALL_RESULT_INTERRUPT'},window.location.origin);
            }
        }
        if(currentState?.active&&!value.active)crm?.contentWindow?.postMessage({type:'IMPE_PHONE_CALL_CHANGED'},window.location.origin);
        if (startsCall) {incomingCompact=false;centerNextCall=true;}
        // Sólo el Analista genera un aviso al finalizar una entrante REAL.
        // La llamada permanece en el historial aun si oculta este aviso.
        if (cfg.analystIncomingResults &&
            currentState?.active && currentState.direction === 'incoming' &&
            !value.active && value.direction === 'incoming' &&
            value.phase === 'incoming-finished' &&
            (Number(currentState.answeredAtMs)>0 || currentState.phase==='in-progress' ||
                currentState.context?.sdkConnected || currentState.context?.pbxAnswered)) {
            const id = incomingCallId(value) || incomingCallId(currentState);
            if (id && /^[a-zA-Z0-9_-]{3,100}$/.test(id)) {
                analystPendingCall = {id, at: Date.now()};
                try { sessionStorage.setItem(analystResultKey, JSON.stringify(analystPendingCall)); }
                catch (_) { /* El historial del servidor es el respaldo. */ }
            }
        }
        // Resumen de Recepción sólo tras confirmar el fin desde PBX.
        if (!cfg.analystIncomingResults && currentState?.active &&
            currentState.direction === 'incoming' && !value.active &&
            value.direction === 'incoming' && value.phase === 'incoming-finished') {
            recentFinishedIncoming = {state:value, id:incomingCallId(value)};
            incomingCompact = false;
        } else if (value.active) {
            recentFinishedIncoming = null;
        }
        // El resumen de Marketing sólo nace de una transición REAL de
        // llamada activa a finalizada en esta sesión, nunca de storage.
        if (!value.active && value.phase === 'finished' &&
            currentState?.active && currentState.direction === 'outgoing' &&
            isManualOutgoing(value)) {
            recentFinishedOutgoing = {
                state: value, until: value.context?.type==='DEVOLUCION'?Infinity:Date.now()+8000
            };
            incomingCompact = false;
        } else if (value.active || value.phase === 'ready' ||
            value.phase === 'idle') {
            recentFinishedOutgoing = null;
        }
        if (outgoingAttempt && !value.active && value.phase === 'error') {
            const reason = String(value.message || 'No se pudo iniciar la llamada.');
            outgoingAttempt = null;
            pendingOutgoingHangup = null;
            dialer?.reportError(reason);
        }
        if (pendingOutgoingHangup &&
            (!value.active || value.direction !== 'outgoing')) {
            pendingOutgoingHangup = null;
        }
        if (outgoingAttempt && !value.active &&
            ['finished','incoming-finished'].includes(value.phase)) {
            outgoingAttempt = null;
        }
        const differentCall = value.direction === 'incoming' &&
            incomingCallId(value) !== incomingCallId(currentState);
        const answered = value.phase === 'in-progress' &&
            currentState?.phase === 'incoming-ringing';
        const ended = !value.active || value.phase === 'incoming-finished';
        if (differentCall || answered || ended) {
            pendingIncomingAction = null;
            actionNotice = '';
        }
        currentState = value;
        window.IMPE_CALL_CLOSURE?.onState(value);
        syncIncomingClock(value);
        lastHeartbeat = Date.now();
        dialer?.sync(value);
        render();
    };
    document.addEventListener('pointerdown',function(){
        try {hostFrame?.contentWindow.IMPE_CALL_AUDIO?.unlock();}catch(_){}
    },{passive:true});

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
        const total = Math.max(0, Math.floor(Number(seconds) || 0));
        return String(Math.floor(total/60)).padStart(2,'0') + ':' +
            String(total%60).padStart(2,'0');
    };
    // Un solo cronómetro fluido para entrantes y salientes del mismo shell.
    // Los eventos PBX posteriores no reinician ni saltan sus dígitos.
    let incomingClock = null;
    const syncIncomingClock = function (call) {
        if (!call?.active ||
            !['incoming','outgoing'].includes(call.direction) ||
            call.phase !== 'in-progress') {
            incomingClock = null;
            return;
        }
        const id = String(call.direction) + ':' +
            (incomingCallId(call) || String(call.destination || ''));
        if (!incomingClock || incomingClock.id !== id) {
            incomingClock = {
                id: id, startedAt: Date.now(),
                initial: Math.max(0, Math.floor(Number(call.duration) || 0))
            };
        }
    };
    const updateIncomingTimer = function (call) {
        if (!call?.active ||
            !['incoming','outgoing'].includes(call.direction)) return;
        const seconds = ['incoming-ringing','dialing','ringing','preparing']
            .includes(call.phase) ? 0 :
            incomingClock && call.phase === 'in-progress'
                ? incomingClock.initial + Math.floor(Math.max(
                    0, Date.now() - incomingClock.startedAt) / 1000)
                : Math.max(0, Number(call.duration) || 0);
        const clockText = formatDuration(seconds);
        if (incomingTimer.textContent !== clockText)
            incomingTimer.textContent = clockText;
        if (incomingCompactTimer.textContent !== clockText)
            incomingCompactTimer.textContent = clockText;
    };
    // Solo cambian los dígitos, sin volver a dibujar ni mover la ventana.
    window.setInterval(function () {
        if (freshState(currentState) && currentState.active) {
            updateIncomingTimer(currentState);
        }
    }, 250);

    const render = function () {
        const state = freshState(currentState) ? currentState : null;
        const incoming = Boolean(state?.active && state.direction === 'incoming');
        const outgoing = Boolean(state?.active && state.direction === 'outgoing');
        if (outgoingAttempt && Date.now() - outgoingAttempt.at > 20000 &&
            !outgoing) {
            outgoingAttempt = null;
            dialer?.reportError('Zadarma no confirmó el inicio de la llamada.');
        }
        const preview = !state?.active && outgoingAttempt
            ? {active:true,direction:'outgoing',phase:'dialing',
                destination:outgoingAttempt.number,muted:false,duration:0,
                context:{type:'TELEPHONY_MANUAL'}} : null;
        if (recentFinishedOutgoing &&
            Date.now() > recentFinishedOutgoing.until) {
            recentFinishedOutgoing = null;
        }
        const finishedPreview = !state?.active && !preview
            ? recentFinishedOutgoing?.state || null : null;
        const incomingFinishedPreview = !state?.active
            ? recentFinishedIncoming?.state || null : null;
        const shownCall = state?.active ? state :
            (incomingFinishedPreview || preview || finishedPreview);
        const finishedIncoming = Boolean(incomingFinishedPreview);
        // Sólo llamadas manuales usan este resumen visual; Seguimiento
        // mantiene su propia pantalla para registrar el resultado.
        const manualOutgoing = isManualOutgoing(shownCall);
        const shownOutgoing = Boolean(shownCall?.direction === 'outgoing' &&
            (manualOutgoing || preview || outgoingAttempt));
        const ongoing = Boolean(state?.active);
        if (analystResultNotice) {
            // No convertir el aviso pendiente en una llamada "activa" al
            // volver a abrir navegador; se conserva sólo como seguimiento.
            analystResultNotice.hidden = !analystPendingCall || ongoing;
        }
        if (!ongoing) {
            nativeTransferOpen = false;
            forceNativeControls = false;
            if(state?.context?.type!=='DEVOLUCION')minimized = false;
            pendingIncomingAction = null;
            actionNotice = '';
        }
        const ready = Boolean(state && state.hostReady &&
            ['ready','prepared','incoming-ringing','answering','rejecting','in-progress',
                'finishing','recovering','dialing','ringing','finished','incoming-finished'].includes(String(state.phase)));
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
            statusText.textContent = 'El teléfono todavía se está conectando.';
            retry.hidden = false;
        }

        // Una sola tarjeta con el diseño del CRM. El widget se conserva
        // montado fuera de pantalla: audio/WebRTC no se desmontan.
        // El panel oficial nunca reemplaza el modal de llamadas entrantes.
        const nativeVisible = (Boolean(state?.hostReady) && ongoing &&
            state?.direction !== 'incoming' && forceNativeControls && !minimized)
            || (Boolean(state?.hostReady) && ongoing && forceNativeControls &&
                nativeTransferOpen && !minimized); // VISIBILIDAD_RECEPTOR_TRANSFER_20261015
        const pending = pendingFor(state);
        if (pendingOutgoingHangup &&
            Date.now() - pendingOutgoingHangup.at > 4500) {
            pendingOutgoingHangup = null;
        }
        // No ocultar el modal al colgar una saliente: mostrar
        // «Finalizando» hasta recibir el evento final del SDK/PBX.
        const finishingIncoming = incoming &&
            (pending === 'hangup' || state.phase === 'finishing');
        const waitingAnswer = incoming && state.phase === 'answering';
        const callbackRegistration=Boolean(!ongoing && (callbackResultOwner||callbackPending||resultFormOwner));
        const customVisible = !callbackRegistration && (incoming || finishedIncoming || shownOutgoing) &&
            !nativeVisible && !minimized && pending !== 'decline';
        if(callbackRegistration)status.hidden=true;
        call.classList.toggle('is-visible', nativeVisible);
        if (incomingPanel) {
            incomingPanel.hidden = !customVisible;
            incomingPanel.classList.toggle('is-compact', incomingCompact);
        }
        const workingCall = Boolean(ongoing && state.phase === 'in-progress' &&
            !finishingIncoming && !pendingOutgoingHangup);
        const transferRequest=state?.context?.transferRequest;
        const transferPending=Boolean((ongoing && transferRequest && !['DEVUELTA','FALLIDA','ENTREGADA'].includes(transferRequest.stage))||window.IMPE_AVAILABILITY?.pending());
        const consultation=Boolean(state?.context?.consultation);
        const transferStage=document.querySelector('[data-transfer-stage]');
        const transferComplete=document.querySelector('[data-transfer-complete]');
        if(transferStage){
            transferStage.hidden=!ongoing||(!transferRequest&&!consultation);
            transferStage.textContent=consultation?'Consulta con recepción. La persona sigue en espera hasta que recepción complete la transferencia.':
                transferRequest?.stage==='DEVUELTA'?(transferRequest.mode==='DIRECTA'?'La centralita confirmó el retorno de la llamada. Retoma la conversación.':'La consulta terminó. Retoma la conversación con la persona.'):
                transferRequest?.stage==='CONSULTANDO'?'El responsable contestó. Habla con él y pulsa Completar transferencia para entregarle a la persona.':
                transferRequest?.stage==='ENTREGANDO'?'Entregando la llamada al responsable…':
                transferRequest?.mode==='CONSULTADA'?'Consultando a la extensión '+transferRequest.extension+'. La persona está en espera.':
                'Transfiriendo a la extensión '+(transferRequest?.extension||'')+'…';
        }
        if(transferComplete){
            transferComplete.hidden=!ongoing||transferRequest?.mode!=='CONSULTADA'||!['CONSULTANDO','ENTREGANDO'].includes(transferRequest.stage);
            transferComplete.disabled=transferRequest?.stage==='ENTREGANDO'&&!transferRequest.retryReady;
        }
        if (transferToggle) {
            transferToggle.hidden = !workingCall || !cfg.canTransfer || consultation;
            transferToggle.disabled = !workingCall || Boolean(ongoing && transferRequest && !['DEVUELTA','FALLIDA','ENTREGADA'].includes(transferRequest.stage));
        }
        if (captureToggle) {
            captureToggle.hidden = !workingCall || !cfg.canCapture || consultation;
            captureToggle.disabled = !workingCall;
        }
        if (!workingCall) {
            if (transferPane) transferPane.hidden = true;
            if (capturePane) capturePane.hidden = true;
            transferToggle?.setAttribute('aria-expanded', 'false');
            captureToggle?.setAttribute('aria-expanded', 'false');
        }
        restore.hidden = !minimized || !(incoming || (ongoing && forceNativeControls));
        if ((incoming || finishedIncoming || shownOutgoing) && incomingPanel) {
            const phoneState = shownCall;
            const ringing = incoming && state.phase === 'incoming-ringing';
            const finished = shownOutgoing && !phoneState.active &&
                phoneState.phase === 'finished';
            const finishing = shownOutgoing && !finished &&
                (Boolean(pendingOutgoingHangup) ||
                    phoneState.phase === 'finishing');
            const dialing = shownOutgoing &&
                !['in-progress','finished'].includes(phoneState.phase);
            // El botón se puede pulsar durante timbrado; el puente comprueba
            // el control del SDK al recibir el clic, sin estado infinito.
            incomingNumber.textContent = String(phoneState.destination || 'Número desconocido');
            incomingTitle.textContent = finishedIncoming ? 'Llamada finalizada' : incoming
                ? (finishingIncoming ? 'Finalizando llamada…' :
                    (waitingAnswer ? 'Conectando llamada…' :
                    (ringing ? 'Llamada entrante' : 'En conversación')))
                : (finished ? 'Llamada finalizada' :
                    (finishing ? 'Finalizando llamada…' :
                    (dialing ? (phoneState.phase==='ringing'?'Timbrando…':'Marcando…') : 'En conversación')));
            if(phoneState.active&&phoneState.phase==='recovering')incomingTitle.textContent='Recuperando conexión de audio…';
            if(consultation)incomingTitle.textContent=phoneState.active?'Consulta con recepción':'Consulta finalizada';
            incomingEyebrow.textContent = (finished || finishedIncoming) ? 'LLAMADA FINALIZADA' :
                (shownOutgoing ? 'LLAMADA SALIENTE' :
                (ringing ? 'RECEPCIÓN TELEFÓNICA' : 'LLAMADA EN CURSO'));
            incomingState.textContent = finishedIncoming
                ? (phoneState.status === 'transferred'
                    ? 'Finalizada · transferencia confirmada por la centralita'
                    : phoneState.status === 'ended-unconfirmed'
                    ? 'Llamada finalizada · actualizando el historial'
                    : 'Finalizada · llamada concluida')
                : shownOutgoing
                ? (finished ? 'Finalizada · registrada en el historial telefónico'
                    : (finishing ? 'Finalizando la conversación…' :
                    (dialing ? (phoneState.phase==='ringing'?'Esperando que contesten':'Marcando desde la extensión ' + cfg.extension)
                        : 'Conversación en curso')))
                : (finishingIncoming ? 'Esperando cierre de Zadarma…' :
                    (waitingAnswer ? 'Conectando audio…' :
                    (ringing ? 'Llamada entrante a la extensión ' + cfg.extension
                    : 'Conversación en curso')));
            if(phoneState.active&&phoneState.phase==='recovering')incomingState.textContent='La llamada puede seguir activa en la centralita. Comprobando su estado.';
            if(incomingOrigin && incomingOriginLabel) {
                const from=String(phoneState.transferFrom||phoneState.context?.transferFrom||'');
                incomingOriginLabel.textContent=consultation?'Consulta de:':shownOutgoing?'Desde:':from?'Transferida desde:':'Recibida en:';
                incomingOrigin.textContent='Extensión '+(shownOutgoing?cfg.extension:from||cfg.extension);
            }
            // El teclado desaparece; el panel empieza en cero.
            if (preview || finished || finishedIncoming) {
                const duration = (finished || finishedIncoming)
                    ? formatDuration(phoneState.duration) : '00:00';
                incomingTimer.textContent = duration;
                incomingCompactTimer.textContent = duration;
            } else updateIncomingTimer(phoneState);
            answerButton.hidden = !ringing;
            declineButton.hidden = !ringing;
            answerButton.disabled = !ringing || Boolean(pending);
            declineButton.disabled = !ringing || Boolean(pending);
            compactAnswer.hidden = !ringing;
            compactDecline.hidden = !ringing;
            compactAnswer.disabled = !ringing || Boolean(pending);
            compactDecline.disabled = !ringing || Boolean(pending);
            muteButton.hidden = finished || finishedIncoming || finishing ||
                finishingIncoming || waitingAnswer || ringing ||
                (shownOutgoing && dialing);
            hangupButton.hidden = finished || finishedIncoming || finishing ||
                finishingIncoming || waitingAnswer || ringing;
            compactMute.hidden = finished || finishedIncoming || finishing ||
                finishingIncoming || waitingAnswer || ringing ||
                (shownOutgoing && dialing);
            compactHangup.hidden = finished || finishedIncoming || finishing ||
                finishingIncoming || waitingAnswer || ringing;
            const callbackPending=Boolean((finished||finishedIncoming)&&phoneState.context?.type==='DEVOLUCION'&&Number(phoneState.context.callbackAttempt)>0&&!callbackSaved.has(Number(phoneState.context.callbackAttempt)));
            const callbackRegister=document.querySelector('[data-callback-register-finished]');if(callbackRegister)callbackRegister.hidden=!callbackPending;
            if (closeFinished) closeFinished.hidden = !(finished || finishedIncoming)||callbackPending;
            hangupButton.disabled = Boolean(pending) || Boolean(preview);
            compactHangup.disabled = Boolean(pending) || Boolean(preview);
            // Mismo icono, texto y estado activo del modal de Seguimiento.
            const muteLabel = phoneState.muted ? 'Activar micrófono' : 'Silenciar';
            const muteIcon = phoneState.muted ? 'bi-mic' : 'bi-mic-mute';
            muteButton.innerHTML = '<i class="bi ' + muteIcon +
                '" aria-hidden="true"></i><span>' + muteLabel + '</span>';
            muteButton.classList.toggle('is-active', Boolean(phoneState.muted));
            compactMute.innerHTML = '<i class="bi ' + muteIcon + '" aria-hidden="true"></i>';
            compactMute.classList.toggle('is-active', Boolean(phoneState.muted));
            compactMute.title = muteLabel;
            compactMute.setAttribute('aria-label', muteLabel);
            notice.hidden = !actionNotice || incomingCompact;
            notice.textContent = actionNotice;
            centerIncoming();
        }
        callTitle.textContent = finishedIncoming ? 'Llamada finalizada' :
            incoming ? 'Llamada entrante' :
            ongoing ? 'Llamada en curso' : 'Teléfono institucional';
        callStatus.textContent = incoming ?
            'Desde ' + String(state.destination || 'Número desconocido') :
            'Extensión ' + cfg.extension;
        if (callHelp) {
            callHelp.textContent = incoming
                ? 'Utiliza Contestar en el teléfono. Si no aparece, espera a que termine de conectar.'
                : 'Teléfono de la extensión ' + cfg.extension + '.';
        }

        // Sólo se hace visible la interfaz oficial si la llamada es entrante.
        // Para salientes, el panel del CRM sigue teniendo sus controles.
        if (hostFrame) {
            hostFrame.dataset.callDirection = incoming ? 'incoming' : 'outgoing';
        }
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

    const readState = function (onBoot) {
        try {
            const saved = JSON.parse(localStorage.getItem(stateKey) || 'null');
            // El iframe WebRTC debe confirmar de nuevo cualquier llamada.
            // Una llamada activa o finalizada guardada NO es una llamada viva.
            if (onBoot && (saved?.active || saved?.phase === 'finished')) return;
            updateState(saved);
        } catch (e) { /* sin estado anterior */ }
    };
    channel?.addEventListener('message', function (event) {
        if (event.data?.type === 'STATE') updateState(event.data.state);
    });
    window.addEventListener('storage', function (event) {
        if (event.key === stateKey && event.newValue) readState(false);
    });

    document.querySelector('[data-shell-minimize]')?.addEventListener('click', function () {
        minimized = true; render();
    });
    document.querySelector('[data-incoming-minimize]')?.addEventListener('click',
        toggleIncomingCompact);
    incomingHeader?.addEventListener('pointerdown', function (event) {
        if (event.button !== 0 ||
            event.target?.closest?.('button,a,input,select,textarea')) return;
        const rect = incomingPanel.getBoundingClientRect();
        incomingDrag = {
            id: event.pointerId,
            offsetX: event.clientX - rect.left,
            offsetY: event.clientY - rect.top
        };
        incomingPanel.style.left = rect.left + 'px';
        incomingPanel.style.top = rect.top + 'px';
        incomingPanel.style.right = 'auto';
        incomingPanel.style.bottom = 'auto';
        incomingPanel.classList.add('is-dragging');
        try { incomingHeader.setPointerCapture(event.pointerId); } catch (_) {}
        event.preventDefault();
    });
    incomingHeader?.addEventListener('pointermove', function (event) {
        if (!incomingDrag || incomingDrag.id !== event.pointerId) return;
        const rect = incomingPanel.getBoundingClientRect();
        incomingPanel.style.left = Math.max(8,Math.min(
            Math.max(8,window.innerWidth-rect.width-8),
            event.clientX - incomingDrag.offsetX)) + 'px';
        incomingPanel.style.top = Math.max(8,Math.min(
            Math.max(8,window.innerHeight-rect.height-8),
            event.clientY - incomingDrag.offsetY)) + 'px';
        event.preventDefault();
    });
    const endIncomingDrag = function (event) {
        if (!incomingDrag || incomingDrag.id !== event.pointerId) return;
        incomingDrag = null;
        incomingPanel.classList.remove('is-dragging');
        try { localStorage.setItem(incomingPositionKey, JSON.stringify({
            left: Number.parseFloat(incomingPanel.style.left),
            top: Number.parseFloat(incomingPanel.style.top)
        })); } catch (_) {}
    };
    incomingHeader?.addEventListener('pointerup', endIncomingDrag);
    incomingHeader?.addEventListener('pointercancel', endIncomingDrag);
    window.addEventListener('resize', clampIncoming);
    window.visualViewport?.addEventListener('resize',clampIncoming);
    // El observador puede reabrir la tarjeta finalizada después del resize.
    // Ajustar también al cambiar su contenido o recuperar la visibilidad.
    if(typeof window.ResizeObserver==='function' && incomingPanel){
        new window.ResizeObserver(()=>window.requestAnimationFrame(clampIncoming)).observe(incomingPanel);
    }
    const answerIncoming = function () {
        if (!freshState(currentState) || !currentState.active ||
            currentState.phase !== 'incoming-ringing' ||
            pendingFor(currentState)) return;
        if (!providerBridge()?.answer()) {
            actionNotice = providerBridge()?.error() ||
                'No se pudo contestar la llamada.';
        } else {
            // Sin alertas: sólo bloquear dobles clics mientras confirma PBX.
            actionNotice = '';
            pendingIncomingAction = {
                action:'answer',callId:incomingCallId(currentState),at:Date.now()
            };
        }
        render();
    };
    answerButton?.addEventListener('click', answerIncoming);
    compactAnswer?.addEventListener('click', answerIncoming);
    const declineIncoming = function () {
        if (!freshState(currentState) || !currentState.active ||
            currentState.phase !== 'incoming-ringing' ||
            pendingFor(currentState)) return;
        if (!providerBridge()?.decline()) {
            actionNotice = providerBridge()?.error() ||
                'No se pudo rechazar la llamada.';
        } else {
            // Ocultar inmediatamente; PBX confirmará el fin en segundo plano.
            actionNotice = '';
            pendingIncomingAction = {
                action:'decline',callId:incomingCallId(currentState),at:Date.now()
            };
        }
        render();
    };
    declineButton?.addEventListener('click', declineIncoming);
    compactDecline?.addEventListener('click', declineIncoming);
    const toggleIncomingMute = function () {
        if (!freshState(currentState) || currentState.phase !== 'in-progress') return;
        sendPhoneCommand('MUTE', {muted:!Boolean(currentState.muted)});
    };
    muteButton?.addEventListener('click', toggleIncomingMute);
    compactMute?.addEventListener('click', toggleIncomingMute);
    closeFinished?.addEventListener('click', function () {
        if (recentFinishedIncoming) {
            recentFinishedIncoming = null;
            render();
            return;
        }
        if (!recentFinishedOutgoing) return;
        const callToken = String(recentFinishedOutgoing.state.callToken || '');
        recentFinishedOutgoing = null;
        sendPhoneCommand('CLEAR_FINISHED', {callToken});
        render();
    });
    const hangupIncoming = function () {
        if (!freshState(currentState) || !currentState.active) return;
        if (currentState.direction === 'outgoing') {
            if (pendingOutgoingHangup) return;
            const phone = crmPhone();
            if (!phone?.hangup) {
                actionNotice = 'No se encontró el control para colgar la llamada.';
            } else {
                phone.hangup();
                actionNotice = '';
                forceNativeControls = false;
                nativeTransferOpen = false;
                pendingOutgoingHangup = {at:Date.now()};
                outgoingAttempt = null;
            }
            render();
            return;
        }
        if (currentState.phase !== 'in-progress' ||
            pendingFor(currentState)) return;
        if (!providerBridge()?.hangup()) {
            actionNotice = providerBridge()?.error() ||
                'No se pudo finalizar la llamada.';
        } else {
            // SDK recibió finishCall(): el modal se oculta ya,
            // sin esperar el sondeo. Si PBX no confirma, reaparece.
            actionNotice = '';
            pendingIncomingAction = {
                action:'hangup',callId:incomingCallId(currentState),at:Date.now()
            };
        }
        render();
    };
    hangupButton?.addEventListener('click', hangupIncoming);
    document.querySelector('[data-callback-register-finished]')?.addEventListener('click',()=>window.IMPE_TELEPHONY_SHELL.openPendingResult());
    window.addEventListener('message',event=>{
        if(event.origin!==window.location.origin||event.source!==crm?.contentWindow||event.data?.type!=='IMPE_CALLBACK_RESULT_SAVED')return;
        const id=Number(event.data.attemptId);
        if(id>0&&callbackPending?.id===id){const token=callbackPending.callToken;callbackSaved.add(id);callbackPending=null;callbackResultOwner=0;rememberCallback();recentFinishedOutgoing=null;if(!currentState?.active)sendPhoneCommand('CLEAR_FINISHED',{callToken:token});render();}
    });
    document.querySelector('[data-transfer-complete]')?.addEventListener('click',()=>sendPhoneCommand('COMPLETE_TRANSFER'));
    window.addEventListener('impe:availability-state',()=>render());
    compactHangup?.addEventListener('click', hangupIncoming);
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

    const dismissAnalystResult = function () {
        analystPendingCall = null;
        try { sessionStorage.removeItem(analystResultKey); } catch (_) {}
        render();
    };
    analystResultButton?.addEventListener('click', function () {
        if (!analystPendingCall || !cfg.incomingResultsUrl || !crm) return;
        const url = new URL(String(cfg.incomingResultsUrl), window.location.href);
        if (url.origin !== window.location.origin) return;
        url.searchParams.set('pbx_call_id', analystPendingCall.id);
        // Abrir el formulario NO significa que MySQL haya guardado el resultado.
        // Conservar el pendiente hasta la confirmación IMPE_ANALYST_RESULT_SAVED
        // emitida por el iframe después del guardado real.
        crm.src = url.toString();
    });
    analystResultDismiss?.addEventListener('click', dismissAnalystResult);

    // Si una petición expira, el login no debe quedar atrapado dentro del
    // iframe: salir del shell desmonta también el contexto WebRTC.
    crm?.addEventListener('load', function () {
        try {
            if(resultFormOwner && !crm.contentDocument?.querySelector('#modalLlamadaEntranteAnalista.show')){
                resultFormOwner=null;
                render();
            }
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
        // Si el Analista abrió una pestaña mientras otra todavía retenía
        // el lease, volver a intentar después de que expire; no esperar
        // a que pulse el auricular. Jamás asumir en verde una conexión falsa.
        if (autoEnabled && !hostFrame && !owned &&
            Date.now() - lastRecoveryAttempt > 12000) {
            const lease = getLease();
            const ocupado = lease && lease.id !== owner &&
                Date.now() - Number(lease.time || 0) < 9000;
            if (!ocupado) {
                lastRecoveryAttempt = Date.now();
                try { ensureHost(); }
                catch (error) { mountFailed = String(error?.message || error); }
            }
        }
        const hostSinConexion = !freshState(currentState) ||
            (currentState && !currentState.hostReady &&
                String(currentState.phase || '') === 'error');
        const montajeRecuperable = !mountFailed ||
            mountFailed.startsWith('No se pudo cargar el motor telefónico');
        if (autoEnabled && hostFrame && hostSinConexion &&
            !currentState?.active && montajeRecuperable &&
            Date.now() - hostStartedAt > 25000 &&
            Date.now() - lastRecoveryAttempt > 60000) {
            // Reintentar cada minuto, incluso después de tres fallos antiguos.
            // NUNCA reiniciar el SDK durante una llamada activa ni si otra
            // pestaña conserva la extensión. La disponibilidad real depende
            // del navegador, la red y el registro SIP de Zadarma.
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

    readState(true);
    let autoEnabled = cfg.autoReception === true;
    // El Analista con extensión entrante activa debe intentar iniciar WebRTC
    // en cada login. Una preferencia vieja de Marketing / navegador no puede
    // desactivar silenciosamente su recepción.
    if (autoEnabled && !cfg.alwaysReceive && !cfg.analystIncomingResults) {
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
