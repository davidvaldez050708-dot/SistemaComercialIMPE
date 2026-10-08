(function () {
    'use strict';

    const userId = Number(window.IMPE_CURRENT_USER_ID || 0);

    if (userId <= 0) {
        return;
    }

    const stateKey = 'impe:telephony:state:' + userId;
    const commandKey = 'impe:telephony:command:' + userId;
    const positionKey = 'impe:telephony:position:' + userId;
    const viewKey = 'impe:telephony:view:' + userId;
    const registrationKey = 'impe:telephony:registration:' + userId;
    const channelName = 'impe-telephony-' + userId;
    const hostWindowName = 'impe_telephony_host_' + userId;
    const hostVersion = '8';

    const statusUrl =
        new URL(
            'index.php?controller=telefonia&action=estadoUsuario',
            window.location.href
        ).toString();
    const hostUrl =
        new URL(
            'index.php?controller=telefonia&action=host',
            window.location.href
        ).toString();
    const transferDestinationsUrl =
        new URL(
            'index.php?controller=telefonia&action=destinosTransferencia',
            window.location.href
        ).toString();

    const channel = typeof BroadcastChannel === 'function'
        ? new BroadcastChannel(channelName)
        : null;

    let availability = null;
    let availabilityPromise = null;
    let state = null;
    let panel = null;
    let listeners = new Set();
    let hostSeenAt = 0;
    let hostWindow = null;
    let drag = null;
    let compact = false;
    let startingCall = false;
    let dismissedFinishedToken = '';
    let preparedDismissed = false;
    let registrationOpenToken = '';

    try {
        registrationOpenToken =
            String(localStorage.getItem(registrationKey) || '');
    } catch (error) {
        // El estado visual también funciona sin almacenamiento local.
    }

    const setRegistrationOpenToken = function (token) {
        registrationOpenToken = String(token || '');
        try {
            if (registrationOpenToken) {
                localStorage.setItem(registrationKey, registrationOpenToken);
            } else {
                localStorage.removeItem(registrationKey);
            }
        } catch (error) {
            // No se bloquea el formulario si falla el almacenamiento.
        }
    };

    const finishedToken = function () {
        return String(
            state?.callToken ||
            state?.finalMetadata?.call_token ||
            ''
        );
    };
    let stageRequestId = 0;
    let transferDestinations = null;
    let transferDestinationsPromise = null;

    try {
        compact =
            localStorage.getItem(viewKey) ===
            'compact';
    } catch (error) {
        compact = false;
    }

    const emptyState = function () {
        return {
            userId: userId,
            extension: '',
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
            answeredAtMs: 0,
            pbxCallId: '',
            callToken: '',
            context: null,
            finalMetadata: null,
            message: '',
            updatedAt: 0
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
            // Un estado local inválido no debe bloquear el dashboard.
        }

        return emptyState();
    };

    state = readState();

    const formatDuration = function (seconds) {
        const total = Math.max(0, Number(seconds) || 0);
        const minutes = Math.floor(total / 60);
        const secs = total % 60;

        return String(minutes).padStart(2, '0') +
            ':' +
            String(secs).padStart(2, '0');
    };

    const currentDuration = function () {
        let duration = Math.max(0, Number(state?.duration || 0));

        if (
            state?.active &&
            Number(state?.answeredAtMs || 0) > 0
        ) {
            duration = Math.max(
                duration,
                Math.floor(
                    (
                        Date.now() -
                        Number(state.answeredAtMs)
                    ) / 1000
                )
            );
        }

        return duration;
    };

    const statusText = function () {
        const labels = {
            loading: 'Preparando teléfono…',
            ready: 'Teléfono listo',
            dialing: 'Marcando…',
            ringing: 'Timbrando…',
            'incoming-ringing': 'Llamada entrante…',
            'incoming-finished': 'Llamada entrante finalizada',
            transferred: 'Llamada transferida',
            'in-progress': 'Llamada en curso',
            finishing: 'Finalizando…',
            completed: 'Llamada finalizada',
            busy: 'Línea ocupada',
            'no-answer': 'Sin respuesta',
            failed: 'Llamada fallida',
            canceled: 'Llamada cancelada',
            interrupted: 'Telefonía interrumpida',
            preparing: 'Preparando llamada…',
            prepared: 'Teléfono listo',
            error: 'Telefonía no disponible'
        };

        if (state?.message) {
            return String(state.message);
        }

        return (
            labels[String(state?.status || '')] ||
            labels[String(state?.phase || '')] ||
            'Telefonía activa'
        );
    };

    const emit = function () {
        listeners.forEach(function (listener) {
            try {
                listener(state);
            } catch (error) {
                console.error(error);
            }
        });

        document.dispatchEvent(
            new CustomEvent(
                'impe:telephony-state',
                { detail: state }
            )
        );
    };

    const updateState = function (nextState) {
        if (
            !nextState ||
            Number(nextState.userId || 0) !== userId
        ) {
            return;
        }

        const incomingPhase =
            String(nextState.phase || '');

        if (
            preparedDismissed &&
            !nextState.active &&
            incomingPhase === 'prepared'
        ) {
            return;
        }

        if (nextState.active) {
            preparedDismissed = false;
        }

        const previousActive =
            Boolean(state?.active);
        const previousToken =
            String(state?.callToken || '');

        const incomingToken =
            String(
                nextState.callToken ||
                nextState.finalMetadata
                    ?.call_token ||
                ''
            );

        if (
            dismissedFinishedToken &&
            String(nextState.phase || '') ===
                'finished' &&
            incomingToken ===
                dismissedFinishedToken
        ) {
            return;
        }

        state = Object.assign(
            emptyState(),
            nextState
        );

        if (
            dismissedFinishedToken &&
            (
                state.active ||
                (
                    incomingToken &&
                    incomingToken !==
                        dismissedFinishedToken
                )
            )
        ) {
            dismissedFinishedToken = '';
        }

        if (state.hostReady) {
            hostSeenAt = Date.now();
        }

        if (
            state.active ||
            [
                'finished',
                'error'
            ].includes(
                String(state.phase || '')
            )
        ) {
            startingCall = false;
        }

        const startedNewCall =
            state.active &&
            (
                !previousActive ||
                (
                    state.callToken &&
                    String(state.callToken) !==
                        previousToken
                )
            );

        if (startedNewCall) {
            setRegistrationOpenToken('');
            compact = false;

            try {
                localStorage.setItem(
                    viewKey,
                    'expanded'
                );
            } catch (error) {
                // El modo visual no afecta la llamada.
            }
        }

        renderPanel();
        emit();
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

    const sendCommand = function (action, payload) {
        const message = {
            type: 'COMMAND',
            id: randomToken(),
            action: String(action || ''),
            payload: payload || {},
            createdAt: Date.now()
        };

        if (channel) {
            channel.postMessage(message);
        }

        try {
            localStorage.setItem(
                commandKey,
                JSON.stringify(message)
            );
        } catch (error) {
            if (!channel) {
                throw new Error(
                    'El navegador no permite sincronizar la llamada.'
                );
            }
        }
    };

    const probe = async function (forceRefresh = false) {
        // Cuando el administrador asigne una extensión durante esta sesión,
        // invalidar la caché para que el marcador detecte el cambio sin salir.
        if (forceRefresh && !state?.active) {
            availability = null;
        }

        if (availability) {
            return availability;
        }

        if (availabilityPromise) {
            return availabilityPromise;
        }

        availabilityPromise = fetch(
            statusUrl,
            {
                method: 'GET',
                credentials: 'same-origin',
                cache: 'no-store',
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'fetch'
                }
            }
        ).then(async function (response) {
            const data = await response.json();

            if (!response.ok || !data.ok) {
                throw new Error(
                    data.mensaje ||
                    'La telefonía no está configurada para tu usuario.'
                );
            }

            availability = data;
            return data;
        }).finally(function () {
            availabilityPromise = null;
        });

        return availabilityPromise;
    };

    const concealHostWindow = function (popup) {
        if (!popup || popup.closed) {
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

        /*
         * Primero intentamos desplazar el host fuera del área visible.
         * Algunos navegadores lo limitan por seguridad; en ese caso queda
         * pegado a la esquina inferior derecha con el tamaño mínimo posible.
         */
        const hiddenLeft =
            screenLeft + screenWidth + 80;
        const hiddenTop =
            screenTop + screenHeight + 80;
        const fallbackLeft =
            Math.max(
                screenLeft,
                screenLeft + screenWidth - 130
            );
        const fallbackTop =
            Math.max(
                screenTop,
                screenTop + screenHeight - 90
            );

        try {
            popup.resizeTo(120, 80);
        } catch (error) {
            // El navegador puede imponer un tamaño mínimo mayor.
        }

        try {
            popup.moveTo(
                hiddenLeft,
                hiddenTop
            );
        } catch (error) {
            try {
                popup.moveTo(
                    fallbackLeft,
                    fallbackTop
                );
            } catch (moveError) {
                // La posición final queda bajo control del navegador.
            }
        }

        try {
            popup.blur();
            window.focus();
        } catch (error) {
            // El foco también puede quedar bajo control del navegador.
        }
    };

    const openHostSynchronously = function () {
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
        const hiddenLeft =
            screenLeft + screenWidth + 80;
        const hiddenTop =
            screenTop + screenHeight + 80;

        const features = [
            'popup=yes',
            'width=120',
            'height=80',
            'left=' + hiddenLeft,
            'top=' + hiddenTop,
            'resizable=no',
            'scrollbars=no',
            'menubar=no',
            'toolbar=no',
            'location=no',
            'status=no'
        ].join(',');

        let popup = null;

        try {
            popup = window.open(
                '',
                hostWindowName,
                features
            );
        } catch (error) {
            popup = null;
        }

        if (!popup) {
            throw new Error(
                'El navegador bloqueó la ventana de telefonía. Permite ventanas emergentes para este sitio.'
            );
        }

        hostWindow = popup;

        try {
            const root =
                popup.document?.documentElement;
            const isHost =
                root?.dataset
                    ?.impeTelephonyHost === '1';
            const currentVersion =
                String(
                    root?.dataset
                        ?.impeTelephonyHostVersion ||
                    ''
                );

            if (
                !isHost ||
                currentVersion !== hostVersion
            ) {
                popup.location.replace(hostUrl);
            }
        } catch (error) {
            // Si temporalmente no se puede inspeccionar, se conserva la ventana nombrada.
        }

        /*
         * Se repite el intento porque Chrome puede reposicionar la ventana
         * durante la carga del documento de destino.
         */
        concealHostWindow(popup);

        [40, 140, 350, 800].forEach(function (delay) {
            window.setTimeout(
                function () {
                    concealHostWindow(popup);
                },
                delay
            );
        });

        return popup;
    };

    const hostUsable = function () {
        const recent =
            state?.hostReady &&
            Date.now() - hostSeenAt < 3500;

        if (!recent) {
            return false;
        }

        if (state?.active) {
            return true;
        }

        return [
            'ready',
            'prepared',
            'finished'
        ].includes(
            String(state?.phase || '')
        );
    };

    const waitForHost = function () {
        if (hostUsable()) {
            return Promise.resolve(true);
        }

        return new Promise(function (resolve, reject) {
            const startedAt = Date.now();

            const tick = function () {
                sendCommand('PING', {});

                if (
                    state?.hostReady &&
                    Date.now() - hostSeenAt < 3500 &&
                    String(state?.phase || '') === 'error'
                ) {
                    reject(
                        new Error(
                            state.message ||
                            'No fue posible iniciar el motor WebRTC.'
                        )
                    );
                    return;
                }

                if (hostUsable()) {
                    resolve(true);
                    return;
                }

                if (Date.now() - startedAt >= 15000) {
                    reject(
                        new Error(
                            'La ventana telefónica no terminó de iniciar.'
                        )
                    );
                    return;
                }

                setTimeout(tick, 220);
            };

            tick();
        });
    };

    const prepare = function (options) {
        const opts = options || {};
        let popup = null;

        if (opts.openHost === true) {
            popup = openHostSynchronously();
        }

        return probe().then(function (info) {
            if (opts.openHost !== true) {
                return info;
            }

            return waitForHost().then(function () {
                return Object.assign({}, info, {
                    hostWindow: popup
                });
            });
        });
    };

    const persistClientState = function () {
        state.updatedAt = Date.now();

        try {
            localStorage.setItem(
                stateKey,
                JSON.stringify(state)
            );
        } catch (error) {
            // BroadcastChannel y el estado en memoria siguen disponibles.
        }

        renderPanel();
        emit();
    };

    const stageCall = async function (payload) {
        const info = await probe();

        if (!info?.permite_salientes) {
            throw new Error(
                'Tu extensión no tiene habilitadas llamadas salientes.'
            );
        }

        const requestId =
            ++stageRequestId;
        preparedDismissed = false;

        const data = payload || {};
        const destination =
            String(data.destination || '').trim();

        if (!destination) {
            throw new Error(
                'El número telefónico no es válido.'
            );
        }

        if (state?.active) {
            throw new Error(
                'Ya existe una llamada activa.'
            );
        }

        if (
            state?.phase === 'finished' &&
            state?.finalMetadata
        ) {
            throw new Error(
                'Primero registra el resultado de la llamada anterior.'
            );
        }

        startingCall = false;
        compact = false;
        saveViewMode();

        state = Object.assign(
            emptyState(),
            {
                phase: 'preparing',
                status: 'preparing',
                active: false,
                destination: destination,
                institution:
                    String(data.institution || '').trim(),
                context: data.context || null,
                message: ''
            }
        );
        persistClientState();

        try {
            const info = await probe();

            if (
                requestId !== stageRequestId ||
                preparedDismissed
            ) {
                return state;
            }

            state = Object.assign(
                {},
                state,
                {
                    extension:
                        String(info.extension || ''),
                    phase: 'prepared',
                    status: 'ready',
                    message: ''
                }
            );
            persistClientState();

            /*
             * Si el host técnico ya estaba abierto por una llamada anterior,
             * también actualizamos su contexto para que no sobrescriba la
             * preparación mientras espera el clic definitivo en "Llamar".
             */
            if (
                requestId !== stageRequestId ||
                preparedDismissed
            ) {
                return state;
            }

            sendCommand(
                'STAGE',
                {
                    destination: destination,
                    institution:
                        String(data.institution || '').trim(),
                    context: data.context || null
                }
            );

            return state;
        } catch (error) {
            if (
                requestId !== stageRequestId ||
                preparedDismissed
            ) {
                return state;
            }

            state = emptyState();
            persistClientState();
            throw error;
        }
    };

    const startCall = async function (payload) {
        const info = await probe();

        if (!info?.permite_salientes) {
            throw new Error(
                'Tu extensión no tiene habilitadas llamadas salientes.'
            );
        }

        if (startingCall) {
            return;
        }

        if (state?.active) {
            throw new Error(
                'Ya existe una llamada activa.'
            );
        }

        const data = payload || {
            destination: state?.destination || '',
            institution: state?.institution || '',
            context: state?.context || null
        };

        const destination =
            String(data.destination || '').trim();

        if (!destination) {
            throw new Error(
                'No hay una llamada preparada.'
            );
        }

        startingCall = true;
        renderPanel();

        try {
            /*
             * prepare() abre la ventana dentro del clic real del usuario.
             * El primer botón del flujo solo prepara la tarjeta del CRM.
             */
            await prepare({ openHost: true });

            if (state?.active) {
                startingCall = false;
                throw new Error(
                    'Ya existe una llamada activa.'
                );
            }

            sendCommand(
                'START',
                {
                    destination: destination,
                    institution:
                        String(data.institution || '').trim(),
                    context: data.context || null
                }
            );
        } catch (error) {
            startingCall = false;

            state = Object.assign(
                {},
                state,
                {
                    phase: 'prepared',
                    status: 'ready',
                    message:
                        error.message ||
                        'No fue posible preparar la llamada.'
                }
            );
            persistClientState();
            throw error;
        }
    };

    const cancelPrepared = function () {
        if (
            state?.active ||
            ![
                'preparing',
                'prepared',
                'error'
            ].includes(
                String(state?.phase || '')
            )
        ) {
            return;
        }

        startingCall = false;
        preparedDismissed = true;
        stageRequestId += 1;
        compact = false;
        saveViewMode();

        state = Object.assign(
            emptyState(),
            {
                extension: String(
                    availability?.extension ||
                    state?.extension ||
                    ''
                ),
                hostReady: Boolean(
                    state?.hostReady
                ),
                phase: 'idle',
                status: 'idle',
                updatedAt: Date.now()
            }
        );

        persistClientState();
        sendCommand('CANCEL_STAGE', {});
    };

    const hangup = function () {
        if (!state?.active) {
            return;
        }

        sendCommand('HANGUP', {});
    };

    const toggleMute = function () {
        if (!state?.active) {
            return;
        }

        sendCommand(
            'MUTE',
            { muted: !Boolean(state.muted) }
        );
    };

    const loadTransferDestinations =
        function () {
            if (transferDestinations !== null) {
                return Promise.resolve(
                    transferDestinations
                );
            }

            if (transferDestinationsPromise) {
                return transferDestinationsPromise;
            }

            transferDestinationsPromise =
                fetch(
                    transferDestinationsUrl,
                    {
                        method: 'GET',
                        credentials: 'same-origin',
                        cache: 'no-store',
                        headers: {
                            Accept: 'application/json',
                            'X-Requested-With':
                                'fetch'
                        }
                    }
                ).then(
                    async function (response) {
                        const data =
                            await response.json();

                        if (
                            !response.ok ||
                            !data.ok
                        ) {
                            throw new Error(
                                data.mensaje ||
                                'No fue posible consultar las extensiones.'
                            );
                        }

                        transferDestinations =
                            Array.isArray(
                                data.destinos
                            )
                                ? data.destinos
                                : [];

                        return transferDestinations;
                    }
                ).finally(function () {
                    transferDestinationsPromise =
                        null;
                });

            return transferDestinationsPromise;
        };

    const transferCall = function (
        extension,
        attended
    ) {
        const ext =
            String(extension || '').replace(/\D+/g, '');

        if (
            !state?.active ||
            ext.length < 3 ||
            ext.length > 6
        ) {
            throw new Error(
                'Indica una extensión válida para transferir la llamada.'
            );
        }

        if (!availability?.permite_transferir) {
            throw new Error(
                'Tu perfil no tiene permiso para transferir llamadas.'
            );
        }

        sendCommand(
            'TRANSFER',
            {
                extension: ext,
                attended: Boolean(attended)
            }
        );
    };

    const showProviderControls = function () {
        sendCommand('SHOW_CONTROLS', {});
    };

    const clearFinished = function (callToken) {
        const token =
            String(
                callToken ||
                state?.callToken ||
                state?.finalMetadata
                    ?.call_token ||
                ''
            );

        if (state?.active) {
            return;
        }

        dismissedFinishedToken = token;
        setRegistrationOpenToken('');

        state = Object.assign(
            emptyState(),
            {
                extension:
                    String(
                        availability
                            ?.extension || ''
                    ),
                hostReady:
                    Boolean(
                        state?.hostReady
                    ),
                phase: 'idle',
                status: 'idle',
                updatedAt: Date.now()
            }
        );

        try {
            localStorage.setItem(
                stateKey,
                JSON.stringify(state)
            );
        } catch (error) {
            // La tarjeta ya se ocultó en memoria; el host se sincroniza aparte.
        }

        renderPanel();
        emit();

        sendCommand(
            'CLEAR_FINISHED',
            { callToken: token }
        );
    };

    const subscribe = function (listener) {
        if (typeof listener !== 'function') {
            return function () {};
        }

        listeners.add(listener);
        listener(state);

        return function () {
            listeners.delete(listener);
        };
    };

    const getState = function () {
        return state;
    };

    const currentUrlWithoutPhoneParams = function () {
        const url = new URL(window.location.href);

        [
            'telefonia_seguimiento',
            'telefonia_resultado',
            'telefonia_llamada'
        ].forEach(function (key) {
            url.searchParams.delete(key);
        });

        return url.toString();
    };

    const contextTargetUrl = function (resultMode) {
        const context = state?.context || {};
        const base = String(
            context.originUrl ||
            currentUrlWithoutPhoneParams()
        );
        const url = new URL(base, window.location.href);
        const seguimientoId =
            Number(context.seguimientoId || 0);

        if (seguimientoId > 0) {
            url.searchParams.set(
                'telefonia_seguimiento',
                String(seguimientoId)
            );
        }

        if (resultMode) {
            url.searchParams.set(
                'telefonia_resultado',
                '1'
            );
        } else {
            url.searchParams.set(
                'telefonia_llamada',
                '1'
            );
        }

        return url.toString();
    };

    const tryOpenContextHere = function (resultMode) {
        const seguimientoId =
            Number(state?.context?.seguimientoId || 0);

        if (seguimientoId <= 0) {
            return false;
        }

        const button = document.querySelector(
            '[data-work-follow-id="' +
            seguimientoId +
            '"]'
        );

        if (!button) {
            return false;
        }

        button.click();

        const eventName = resultMode
            ? 'impe:telephony-register-result'
            : 'impe:telephony-restore-call';

        let attempts = 0;

        const wait = function () {
            attempts += 1;

            const offcanvas = document.getElementById(
                'offcanvasSeguimientoTrabajo'
            );
            const currentId = Number(
                offcanvas?.dataset?.flowSeguimientoId || 0
            );

            if (currentId === seguimientoId) {
                document.dispatchEvent(
                    new CustomEvent(
                        eventName,
                        { detail: state }
                    )
                );
                return;
            }

            if (attempts < 50) {
                setTimeout(wait, 100);
            }
        };

        setTimeout(wait, 80);
        return true;
    };

    const openContext = function (resultMode) {
        if (
            resultMode &&
            !state?.active &&
            state?.phase === 'finished' &&
            String(state?.context?.type || '').toUpperCase() ===
                'VINCULACION'
        ) {
            // Solo se oculta la tarjeta. Nunca se borra la evidencia
            // técnica hasta guardar y vincular la interacción.
            setRegistrationOpenToken(finishedToken());
            renderPanel();
        }

        if (tryOpenContextHere(Boolean(resultMode))) {
            return;
        }

        window.location.assign(
            contextTargetUrl(Boolean(resultMode))
        );
    };

    const clampPanel = function () {
        if (!panel || panel.hidden) {
            return;
        }

        const rect = panel.getBoundingClientRect();
        const maxLeft = Math.max(
            8,
            window.innerWidth - rect.width - 8
        );
        const maxTop = Math.max(
            8,
            window.innerHeight - rect.height - 8
        );

        const left = Math.min(
            maxLeft,
            Math.max(
                8,
                Number.parseFloat(
                    panel.style.left || rect.left
                ) || 8
            )
        );
        const top = Math.min(
            maxTop,
            Math.max(
                8,
                Number.parseFloat(
                    panel.style.top || rect.top
                ) || 8
            )
        );

        panel.style.left = left + 'px';
        panel.style.top = top + 'px';
        panel.style.right = 'auto';
        panel.style.bottom = 'auto';
    };

    const savePosition = function () {
        if (!panel) {
            return;
        }

        try {
            localStorage.setItem(
                positionKey,
                JSON.stringify({
                    left:
                        Number.parseFloat(panel.style.left || '') || 0,
                    top:
                        Number.parseFloat(panel.style.top || '') || 0
                })
            );
        } catch (error) {
            // La posición no es crítica para la llamada.
        }
    };

    const restorePosition = function () {
        if (!panel) {
            return;
        }

        try {
            const raw =
                localStorage.getItem(positionKey);
            const saved =
                raw ? JSON.parse(raw) : null;

            if (
                saved &&
                Number.isFinite(Number(saved.left)) &&
                Number.isFinite(Number(saved.top))
            ) {
                panel.style.left =
                    Number(saved.left) + 'px';
                panel.style.top =
                    Number(saved.top) + 'px';
                panel.style.right = 'auto';
                panel.style.bottom = 'auto';
            }
        } catch (error) {
            // Se conserva la posición predeterminada.
        }
    };

    const beginDrag = function (event) {
        if (
            !panel ||
            event.button !== 0 ||
            event.target.closest(
                'button, a, input, select, textarea'
            )
        ) {
            return;
        }

        const rect = panel.getBoundingClientRect();

        drag = {
            pointerId: event.pointerId,
            offsetX: event.clientX - rect.left,
            offsetY: event.clientY - rect.top
        };

        panel.classList.add('is-dragging');
        panel.style.left = rect.left + 'px';
        panel.style.top = rect.top + 'px';
        panel.style.right = 'auto';
        panel.style.bottom = 'auto';

        try {
            event.currentTarget.setPointerCapture(
                event.pointerId
            );
        } catch (error) {
            // Pointer capture es una mejora, no un requisito.
        }

        event.preventDefault();
    };

    const moveDrag = function (event) {
        if (
            !drag ||
            event.pointerId !== drag.pointerId ||
            !panel
        ) {
            return;
        }

        const rect = panel.getBoundingClientRect();
        const maxLeft = Math.max(
            8,
            window.innerWidth - rect.width - 8
        );
        const maxTop = Math.max(
            8,
            window.innerHeight - rect.height - 8
        );

        const left = Math.min(
            maxLeft,
            Math.max(
                8,
                event.clientX - drag.offsetX
            )
        );
        const top = Math.min(
            maxTop,
            Math.max(
                8,
                event.clientY - drag.offsetY
            )
        );

        panel.style.left = left + 'px';
        panel.style.top = top + 'px';

        event.preventDefault();
    };

    const endDrag = function (event) {
        if (
            !drag ||
            event.pointerId !== drag.pointerId
        ) {
            return;
        }

        drag = null;
        panel?.classList.remove('is-dragging');
        savePosition();
    };

    const saveViewMode = function () {
        try {
            localStorage.setItem(
                viewKey,
                compact
                    ? 'compact'
                    : 'expanded'
            );
        } catch (error) {
            // El modo visual no es crítico.
        }
    };

    const setCompact = function (value) {
        compact = Boolean(value);
        saveViewMode();
        renderPanel();

        window.setTimeout(
            clampPanel,
            0
        );
    };

    const expand = function () {
        setCompact(false);
    };

    const createPanel = function () {
        if (panel) {
            return;
        }

        panel = document.createElement('section');
        panel.className = 'persistent-phone-panel';
        panel.hidden = true;
        panel.setAttribute(
            'data-persistent-phone',
            ''
        );
        panel.innerHTML =
            '<header class="persistent-phone-header" data-phone-drag-handle>' +
                '<span class="persistent-phone-live" aria-hidden="true"><i></i></span>' +
                '<div class="persistent-phone-heading">' +
                    '<span data-phone-eyebrow>LLAMADA INSTITUCIONAL</span>' +
                    '<strong data-phone-title>Telefonía</strong>' +
                '</div>' +
                '<strong class="persistent-phone-header-timer" data-phone-header-timer>00:00</strong>' +
                '<button type="button" class="persistent-phone-toggle" data-phone-toggle title="Minimizar" aria-label="Minimizar llamada">' +
                    '<i class="bi bi-dash-lg"></i>' +
                    '<span>Minimizar</span>' +
                '</button>' +
                '<button type="button" class="persistent-phone-close" data-phone-close title="Cerrar" aria-label="Cerrar teléfono">' +
                    '<i class="bi bi-x-lg"></i>' +
                '</button>' +
            '</header>' +
            '<div class="persistent-phone-body">' +
                '<div class="persistent-phone-number" data-phone-number>—</div>' +
                '<div class="persistent-phone-origin">' +
                    '<i class="bi bi-building-check" aria-hidden="true"></i>' +
                    '<span>Desde: <strong data-phone-extension>Extensión —</strong> · Telefonía IP</span>' +
                '</div>' +
                '<div class="persistent-phone-state">' +
                    '<span data-phone-status>Preparando…</span>' +
                    '<strong data-phone-timer>00:00</strong>' +
                '</div>' +
                '<div class="persistent-phone-actions" data-phone-active-actions>' +
                    '<button type="button" class="persistent-phone-action is-primary" data-phone-start>' +
                        '<i class="bi bi-telephone"></i><span>Llamar</span>' +
                    '</button>' +
                    '<button type="button" class="persistent-phone-action" data-phone-mute>' +
                        '<i class="bi bi-mic-mute"></i><span>Silenciar</span>' +
                    '</button>' +
                    '<button type="button" class="persistent-phone-action" data-phone-transfer-toggle>' +
                        '<i class="bi bi-arrow-left-right"></i><span>Transferir</span>' +
                    '</button>' +
                    '<button type="button" class="persistent-phone-action is-danger" data-phone-hangup>' +
                        '<i class="bi bi-telephone-x"></i><span>Colgar</span>' +
                    '</button>' +
                '</div>' +
                '<div class="persistent-phone-transfer" data-phone-transfer-panel hidden>' +
                    '<div class="persistent-phone-transfer-head">' +
                        '<strong>Transferir llamada</strong>' +
                        '<button type="button" data-phone-provider-controls>Controles Zadarma</button>' +
                    '</div>' +
                    '<select data-phone-transfer-destination>' +
                        '<option value="">Selecciona un usuario o escribe la extensión</option>' +
                    '</select>' +
                    '<input type="text" inputmode="numeric" maxlength="6" placeholder="Extensión, ej. 101" data-phone-transfer-extension>' +
                    '<div class="persistent-phone-transfer-actions">' +
                        '<button type="button" data-phone-transfer-directa>Transferencia directa</button>' +
                        '<button type="button" data-phone-transfer-consultada>Consultar primero</button>' +
                    '</div>' +
                    '<small data-phone-transfer-help>Directa: #ext#. Consultada: *ext#.</small>' +
                '</div>' +
                '<button type="button" class="persistent-phone-result" data-phone-result hidden>' +
                    '<i class="bi bi-journal-check"></i>' +
                    '<span>Registrar resultado</span>' +
                '</button>' +
            '</div>' +
            '<div class="persistent-phone-compact-actions" data-phone-compact-actions>' +
                '<button type="button" class="persistent-phone-compact-button is-primary" data-phone-compact-start title="Llamar" aria-label="Llamar">' +
                    '<i class="bi bi-telephone"></i>' +
                '</button>' +
                '<button type="button" class="persistent-phone-compact-button" data-phone-compact-mute title="Silenciar" aria-label="Silenciar">' +
                    '<i class="bi bi-mic-mute"></i>' +
                '</button>' +
                '<button type="button" class="persistent-phone-compact-button is-danger" data-phone-compact-hangup title="Colgar" aria-label="Colgar">' +
                    '<i class="bi bi-telephone-x"></i>' +
                '</button>' +
                '<button type="button" class="persistent-phone-compact-result" data-phone-compact-result hidden>' +
                    '<i class="bi bi-journal-check"></i>' +
                    '<span>Registrar resultado</span>' +
                '</button>' +
            '</div>';

        document.body.appendChild(panel);
        restorePosition();

        const handle = panel.querySelector(
            '[data-phone-drag-handle]'
        );

        handle?.addEventListener(
            'pointerdown',
            beginDrag
        );
        handle?.addEventListener(
            'pointermove',
            moveDrag
        );
        handle?.addEventListener(
            'pointerup',
            endDrag
        );
        handle?.addEventListener(
            'pointercancel',
            endDrag
        );

        panel.querySelector(
            '[data-phone-toggle]'
        )?.addEventListener(
            'click',
            function () {
                setCompact(!compact);
            }
        );

        panel.querySelector(
            '[data-phone-close]'
        )?.addEventListener(
            'click',
            function () {
                if (
                    state?.phase === 'finished' &&
                    String(state?.context?.type || '').toUpperCase() === 'DIALER'
                ) {
                    clearFinished();
                } else {
                    cancelPrepared();
                }
            }
        );

        [
            '[data-phone-start]',
            '[data-phone-compact-start]'
        ].forEach(function (selector) {
            panel.querySelector(selector)
                ?.addEventListener(
                    'click',
                    function () {
                        void startCall({
                            destination:
                                state?.destination || '',
                            institution:
                                state?.institution || '',
                            context:
                                state?.context || null
                        }).catch(function (error) {
                            console.warn(error);
                        });
                    }
                );
        });

        [
            '[data-phone-mute]',
            '[data-phone-compact-mute]'
        ].forEach(function (selector) {
            panel.querySelector(selector)
                ?.addEventListener(
                    'click',
                    toggleMute
                );
        });

        [
            '[data-phone-hangup]',
            '[data-phone-compact-hangup]'
        ].forEach(function (selector) {
            panel.querySelector(selector)
                ?.addEventListener(
                    'click',
                    hangup
                );
        });

        const transferPanel =
            panel.querySelector(
                '[data-phone-transfer-panel]'
            );
        const transferInput =
            panel.querySelector(
                '[data-phone-transfer-extension]'
            );
        const transferSelect =
            panel.querySelector(
                '[data-phone-transfer-destination]'
            );
        const transferHelp =
            panel.querySelector(
                '[data-phone-transfer-help]'
            );

        const renderTransferDestinations =
            function (destinations) {
                if (!transferSelect) {
                    return;
                }

                transferSelect.innerHTML =
                    '<option value="">Selecciona un usuario o escribe la extensión</option>';

                destinations.forEach(
                    function (destination) {
                        const extension =
                            String(
                                destination.extension ||
                                ''
                            ).trim();

                        if (extension === '') {
                            return;
                        }

                        const option =
                            document.createElement(
                                'option'
                            );
                        option.value = extension;
                        option.textContent =
                            (
                                String(
                                    destination.nombre ||
                                    'Usuario'
                                ).trim() ||
                                'Usuario'
                            ) +
                            ' · Ext. ' +
                            extension +
                            (
                                String(
                                    destination.rol ||
                                    ''
                                ).trim() !== ''
                                    ? ' · ' +
                                        String(
                                            destination.rol
                                        ).trim()
                                    : ''
                            );

                        transferSelect.appendChild(
                            option
                        );
                    }
                );
            };

        panel.querySelector(
            '[data-phone-transfer-toggle]'
        )?.addEventListener(
            'click',
            function () {
                if (!transferPanel) {
                    return;
                }

                transferPanel.hidden =
                    !transferPanel.hidden;

                if (!transferPanel.hidden) {
                    if (
                        transferSelect &&
                        transferSelect.options.length <= 1
                    ) {
                        if (transferHelp) {
                            transferHelp.textContent =
                                'Cargando extensiones disponibles…';
                        }

                        void loadTransferDestinations()
                            .then(
                                function (destinations) {
                                    renderTransferDestinations(
                                        destinations
                                    );

                                    if (transferHelp) {
                                        transferHelp.textContent =
                                            destinations.length > 0
                                                ? 'Elige un usuario o captura una extensión manualmente.'
                                                : 'No hay otros usuarios disponibles; puedes capturar una extensión manualmente.';
                                    }
                                }
                            )
                            .catch(
                                function (error) {
                                    if (transferHelp) {
                                        transferHelp.textContent =
                                            error.message ||
                                            'No fue posible cargar las extensiones.';
                                    }
                                }
                            );
                    }

                    transferSelect?.focus();
                    transferInput?.focus({
                        preventScroll: true
                    });
                }
            }
        );

        transferSelect?.addEventListener(
            'change',
            function () {
                if (
                    transferInput &&
                    String(
                        transferSelect.value || ''
                    ).trim() !== ''
                ) {
                    transferInput.value =
                        String(
                            transferSelect.value
                        );
                }
            }
        );

        panel.querySelector(
            '[data-phone-provider-controls]'
        )?.addEventListener(
            'click',
            showProviderControls
        );

        const executeTransfer =
            function (attended) {
                try {
                    transferCall(
                        transferInput?.value || '',
                        attended
                    );

                    if (transferPanel) {
                        transferPanel.hidden = true;
                    }
                } catch (error) {
                    const help =
                        panel.querySelector(
                            '[data-phone-transfer-help]'
                        );

                    if (help) {
                        help.textContent =
                            error.message ||
                            'No fue posible preparar la transferencia.';
                    }
                }
            };

        panel.querySelector(
            '[data-phone-transfer-directa]'
        )?.addEventListener(
            'click',
            function () {
                executeTransfer(false);
            }
        );

        panel.querySelector(
            '[data-phone-transfer-consultada]'
        )?.addEventListener(
            'click',
            function () {
                executeTransfer(true);
            }
        );

        [
            '[data-phone-result]',
            '[data-phone-compact-result]'
        ].forEach(function (selector) {
            panel.querySelector(selector)
                ?.addEventListener(
                    'click',
                    function () {
                        openContext(true);
                    }
                );
        });
    };

    function renderPanel() {
        if (!panel) {
            return;
        }

        const phase =
            String(state?.phase || '');
        const show =
            (
                Boolean(state?.active) ||
                [
                    'preparing',
                    'prepared',
                    'finished',
                    'incoming-finished'
                ].includes(phase)
            ) &&
            !(
                phase === 'finished' &&
                registrationOpenToken !== '' &&
                registrationOpenToken === finishedToken()
            );

        panel.hidden = !show;

        if (!show) {
            return;
        }

        panel.classList.toggle(
            'is-compact',
            compact
        );

        const title =
            panel.querySelector(
                '[data-phone-title]'
            );
        const number =
            panel.querySelector(
                '[data-phone-number]'
            );
        const extensionEl =
            panel.querySelector(
                '[data-phone-extension]'
            );
        const status =
            panel.querySelector(
                '[data-phone-status]'
            );
        const timer =
            panel.querySelector(
                '[data-phone-timer]'
            );
        const headerTimer =
            panel.querySelector(
                '[data-phone-header-timer]'
            );
        const eyebrow =
            panel.querySelector(
                '[data-phone-eyebrow]'
            );
        const toggle =
            panel.querySelector(
                '[data-phone-toggle]'
            );
        const closeButton =
            panel.querySelector(
                '[data-phone-close]'
            );
        const startButton =
            panel.querySelector(
                '[data-phone-start]'
            );
        const compactStart =
            panel.querySelector(
                '[data-phone-compact-start]'
            );
        const mute =
            panel.querySelector(
                '[data-phone-mute]'
            );
        const compactMute =
            panel.querySelector(
                '[data-phone-compact-mute]'
            );
        const hangupButton =
            panel.querySelector(
                '[data-phone-hangup]'
            );
        const transferButton =
            panel.querySelector(
                '[data-phone-transfer-toggle]'
            );
        const transferPanel =
            panel.querySelector(
                '[data-phone-transfer-panel]'
            );
        const compactHangup =
            panel.querySelector(
                '[data-phone-compact-hangup]'
            );
        const activeActions =
            panel.querySelector(
                '[data-phone-active-actions]'
            );
        const resultButton =
            panel.querySelector(
                '[data-phone-result]'
            );
        const compactResult =
            panel.querySelector(
                '[data-phone-compact-result]'
            );
        const live =
            panel.querySelector(
                '.persistent-phone-live'
            );

        const duration =
            formatDuration(
                currentDuration()
            );
        const finished =
            [
                'finished',
                'incoming-finished'
            ].includes(
                String(state.phase || '')
            );

        const incoming =
            String(state.direction || '') ===
                'incoming';
        const isIndependentDialer =
            String(state.context?.type || '').toUpperCase() === 'DIALER';

        if (title) {
            title.textContent =
                incoming
                    ? 'Llamada entrante'
                    : (
                        state.institution ||
                        state.destination ||
                        'Telefonía'
                    );
        }

        if (number) {
            number.textContent =
                incoming
                    ? (
                        'De: ' +
                        String(
                            state.destination || 'Número desconocido'
                        )
                    )
                    : (state.destination || '—');
        }

        if (extensionEl) {
            extensionEl.textContent =
                state.extension
                    ? 'Extensión ' +
                        state.extension
                    : 'Extensión —';
        }

        if (status) {
            status.textContent =
                statusText();
        }

        if (timer) {
            timer.textContent = duration;
        }

        if (headerTimer) {
            headerTimer.textContent =
                duration;
        }

        if (eyebrow) {
            eyebrow.textContent = finished
                ? 'LLAMADA FINALIZADA'
                : (
                    incoming
                        ? 'RECEPCIÓN TELEFÓNICA'
                        : 'LLAMADA INSTITUCIONAL'
                );
        }

        if (toggle) {
            toggle.title = compact
                ? 'Expandir llamada'
                : 'Minimizar llamada';
            toggle.setAttribute(
                'aria-label',
                toggle.title
            );
            toggle.innerHTML = compact
                ? '<i class="bi bi-arrows-angle-expand"></i><span>Expandir</span>'
                : '<i class="bi bi-dash-lg"></i><span>Minimizar</span>';
        }

        live?.classList.toggle(
            'is-finished',
            finished
        );

        if (closeButton) {
            closeButton.hidden =
                state.active ||
                (
                    finished
                        ? !isIndependentDialer
                        : ![
                            'preparing',
                            'prepared',
                            'error'
                        ].includes(phase)
                );
            closeButton.title = finished && isIndependentDialer
                ? 'Cerrar llamada finalizada'
                : 'Cerrar teléfono';
        }

        if (activeActions) {
            activeActions.hidden =
                finished;
        }

        if (resultButton) {
            resultButton.hidden =
                !finished ||
                incoming ||
                isIndependentDialer;
        }

        if (compactResult) {
            compactResult.hidden =
                !finished ||
                incoming ||
                isIndependentDialer;
        }

        const readyToStart =
            !state.active &&
            phase === 'prepared' &&
            !startingCall;

        [startButton, compactStart]
            .forEach(function (button) {
                if (!button) {
                    return;
                }

                button.disabled =
                    !readyToStart;
            });

        const callInProgress =
            state.active &&
            String(state.status || '') ===
                'in-progress';
        const canTransfer =
            callInProgress &&
            Boolean(
                availability?.permite_transferir
            );

        if (transferButton) {
            transferButton.hidden = !canTransfer;
            transferButton.disabled = !canTransfer;
        }

        if (
            transferPanel &&
            !canTransfer
        ) {
            transferPanel.hidden = true;
        }
        const finishing =
            String(state.status || '') ===
                'finishing';

        [mute, compactMute].forEach(
            function (button) {
                if (!button) {
                    return;
                }

                button.disabled =
                    !callInProgress;
                button.classList.toggle(
                    'is-active',
                    Boolean(state.muted)
                );
                button.innerHTML =
                    state.muted
                        ? '<i class="bi bi-mic"></i>' +
                            (button === mute
                                ? '<span>Activar micrófono</span>'
                                : '')
                        : '<i class="bi bi-mic-mute"></i>' +
                            (button === mute
                                ? '<span>Silenciar</span>'
                                : '');
            }
        );

        [hangupButton, compactHangup]
            .forEach(function (button) {
                if (!button) {
                    return;
                }

                button.disabled =
                    !state.active ||
                    finishing;
            });

        window.setTimeout(
            clampPanel,
            0
        );
    }

    const restoreContextFromQuery = function () {
        const params =
            new URLSearchParams(
                window.location.search
            );
        const seguimientoId =
            Number(
                params.get(
                    'telefonia_seguimiento'
                ) || 0
            );

        if (seguimientoId <= 0) {
            return;
        }

        const resultMode =
            params.get(
                'telefonia_resultado'
            ) === '1';
        const callMode =
            params.get(
                'telefonia_llamada'
            ) === '1';

        let attempts = 0;

        const open = function () {
            attempts += 1;

            const button =
                document.querySelector(
                    '[data-work-follow-id="' +
                    seguimientoId +
                    '"]'
                );

            if (!button) {
                if (attempts < 50) {
                    setTimeout(open, 100);
                }
                return;
            }

            button.click();

            const cleanUrl =
                new URL(
                    window.location.href
                );
            cleanUrl.searchParams.delete(
                'telefonia_seguimiento'
            );
            cleanUrl.searchParams.delete(
                'telefonia_resultado'
            );
            cleanUrl.searchParams.delete(
                'telefonia_llamada'
            );
            history.replaceState(
                {},
                '',
                cleanUrl.toString()
            );

            let panelAttempts = 0;

            const notify = function () {
                panelAttempts += 1;

                const offcanvas =
                    document.getElementById(
                        'offcanvasSeguimientoTrabajo'
                    );
                const currentId =
                    Number(
                        offcanvas?.dataset
                            ?.flowSeguimientoId || 0
                    );

                if (
                    currentId ===
                    seguimientoId
                ) {
                    if (resultMode) {
                        document.dispatchEvent(
                            new CustomEvent(
                                'impe:telephony-register-result',
                                { detail: state }
                            )
                        );
                    } else if (callMode) {
                        document.dispatchEvent(
                            new CustomEvent(
                                'impe:telephony-restore-call',
                                { detail: state }
                            )
                        );
                    }
                    return;
                }

                if (panelAttempts < 50) {
                    setTimeout(
                        notify,
                        100
                    );
                }
            };

            setTimeout(notify, 100);
        };

        open();
    };

    if (channel) {
        channel.addEventListener(
            'message',
            function (event) {
                const message =
                    event.data || {};

                if (
                    message.type === 'STATE'
                ) {
                    updateState(
                        message.state
                    );
                }
            }
        );
    }

    addEventListener(
        'storage',
        function (event) {
            if (event.key === registrationKey) {
                registrationOpenToken = String(event.newValue || '');
                renderPanel();
                return;
            }

            if (
                event.key !== stateKey ||
                !event.newValue
            ) {
                return;
            }

            try {
                updateState(
                    JSON.parse(
                        event.newValue
                    )
                );
            } catch (error) {
                console.debug(
                    'Estado telefónico inválido.',
                    error
                );
            }
        }
    );

    document.addEventListener(
        'impe:telephony-registration-unavailable',
        function () {
            setRegistrationOpenToken('');
            renderPanel();
        }
    );

    addEventListener(
        'resize',
        clampPanel
    );

    window.IMPE_TELEPHONY_PERSISTENT = {
        probe: probe,
        prepare: prepare,
        stageCall: stageCall,
        startCall: startCall,
        cancelPrepared: cancelPrepared,
        hangup: hangup,
        toggleMute: toggleMute,
        transferCall: transferCall,
        showProviderControls: showProviderControls,
        clearFinished: clearFinished,
        subscribe: subscribe,
        getState: getState,
        openContext: openContext,
        expand: expand,
        currentUrlWithoutPhoneParams:
            currentUrlWithoutPhoneParams
    };

    document.addEventListener(
        'DOMContentLoaded',
        function () {
            createPanel();

            const receptionButton = document.querySelector(
                '[data-telephony-reception-toggle]'
            );
            const receptionIndicator = receptionButton?.querySelector(
                '[data-telephony-reception-indicator]'
            );
            const marketingReception = document.querySelector(
                '[data-marketing-reception]'
            );
            const marketingBadge = marketingReception?.querySelector(
                '[data-marketing-reception-state]'
            );
            const marketingLabel = marketingReception?.querySelector(
                '[data-marketing-reception-state-text]'
            );
            const marketingAction = marketingReception?.querySelector(
                '[data-marketing-reception-activate]'
            );
            const marketingHint = marketingReception?.querySelector(
                '[data-marketing-reception-hint]'
            );
            let receptionChecking = true;
            let receptionBusy = false;
            let receptionError = '';

            const renderReceptionState = function () {
                if (!receptionButton && !marketingReception) return;

                const assigned = Boolean(availability?.permite_entrantes);
                const active = assigned && hostUsable();
                const awaiting = !assigned && receptionChecking;
                const missing = !assigned && !awaiting &&
                    /extensi[oó]n|asignada/i.test(receptionError);
                const extension = assigned
                    ? String(availability.extension || '')
                    : '';
                const label = active
                    ? 'Recepción activa'
                    : assigned
                        ? 'Recepción por activar'
                        : awaiting
                            ? 'Verificando extensión…'
                            : missing
                                ? 'Extensión pendiente'
                                : 'Recepción no disponible';
                const title = active
                    ? 'Recepción telefónica activa'
                    : assigned
                        ? 'Activar recepción telefónica'
                        : missing
                            ? 'Extensión pendiente: solicita su asignación al administrador'
                            : receptionError || 'Verificando recepción telefónica';

                if (receptionButton) {
                    receptionButton.disabled = !assigned || receptionBusy;
                    receptionButton.classList.toggle('is-active', active);
                    receptionButton.title = title;
                    receptionButton.setAttribute('aria-label', title);
                }
                receptionIndicator?.classList.toggle('is-active', active);

                if (marketingBadge) {
                    marketingBadge.classList.toggle('is-active', active);
                    marketingBadge.classList.toggle('is-pending', missing);
                }
                if (marketingLabel) {
                    marketingLabel.textContent = label +
                        (extension ? ' · Ext. ' + extension : '');
                }
                if (marketingHint) {
                    marketingHint.textContent = active
                        ? 'Extensión ' + extension +
                            ' conectada a WebRTC. Puedes recibir y transferir llamadas mientras permanezca activa.'
                        : assigned
                            ? 'Extensión ' + extension +
                                ' asignada. Pulsa Activar recepción al comenzar tu jornada.'
                            : awaiting
                                ? 'Estamos consultando la extensión telefónica asignada a tu usuario.'
                                : missing
                                    ? 'No tienes una extensión habilitada para recibir llamadas. Solicita al administrador que te la asigne.'
                                    : receptionError || 'La recepción telefónica no está disponible.';
                }
                if (marketingAction) {
                    marketingAction.disabled = !assigned || receptionBusy;
                    marketingAction.innerHTML = active
                        ? '<i class="bi bi-headset" aria-hidden="true"></i> Abrir teléfono'
                        : '<i class="bi bi-headset" aria-hidden="true"></i> Activar recepción';
                }
            };

            const activarRecepcion = function () {
                if (!availability?.permite_entrantes || receptionBusy) return;
                receptionBusy = true;
                receptionError = '';
                renderReceptionState();

                // Mantener la apertura del host WebRTC dentro del clic real:
                // la ventana no puede abrirse tras otra promesa de validación.
                void prepare({openHost: true}).then(function () {
                    sendCommand('PING', {});
                    window.setTimeout(renderReceptionState, 300);
                }).catch(function (error) {
                    console.warn(error);
                    receptionError = String(error?.message || 'No fue posible activar recepción.');
                }).finally(function () {
                    receptionBusy = false;
                    renderReceptionState();
                });
            };

            receptionButton?.addEventListener('click', activarRecepcion);
            marketingAction?.addEventListener('click', activarRecepcion);
            subscribe(renderReceptionState);

            void probe().then(function (info) {
                receptionError = '';
                receptionChecking = false;
                if (info?.permite_entrantes) {
                    sendCommand('PING', {});
                    window.setTimeout(renderReceptionState, 260);
                }
                renderReceptionState();
            }).catch(function (error) {
                receptionChecking = false;
                receptionError = String(error?.message || 'No se encontró una extensión habilitada.');
                renderReceptionState();
            });
            renderReceptionState();

            // Si se cierra el host y deja de enviar latidos, el indicador
            // vuelve a 'Recepción por activar'; nunca queda verde en falso.
            window.setInterval(renderReceptionState, 3000);
            renderPanel();
            restoreContextFromQuery();

            setInterval(
                renderPanel,
                500
            );

            void probe().catch(function () {
                // Un usuario sin extensión puede navegar normalmente.
            });

            if (
                state?.active ||
                [
                    'prepared',
                    'finished'
                ].includes(
                    String(state?.phase || '')
                )
            ) {
                sendCommand('PING', {});
            }
        }
    );
})();
