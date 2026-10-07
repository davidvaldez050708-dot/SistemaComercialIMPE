(function () {
    'use strict';

    const userId = Number(window.IMPE_CURRENT_USER_ID || 0);

    if (userId <= 0) {
        return;
    }

    const stateKey = 'impe:telephony:state:' + userId;
    const commandKey = 'impe:telephony:command:' + userId;
    const positionKey = 'impe:telephony:position:' + userId;
    const channelName = 'impe-telephony-' + userId;
    const hostWindowName = 'impe_telephony_host_' + userId;

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

    const emptyState = function () {
        return {
            userId: userId,
            extension: '',
            provider: 'ZADARMA',
            hostReady: false,
            phase: 'idle',
            active: false,
            muted: false,
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
            'in-progress': 'Llamada en curso',
            finishing: 'Finalizando…',
            completed: 'Llamada finalizada',
            busy: 'Línea ocupada',
            'no-answer': 'Sin respuesta',
            failed: 'Llamada fallida',
            canceled: 'Llamada cancelada',
            interrupted: 'Telefonía interrumpida',
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

        state = Object.assign(emptyState(), nextState);

        if (state.hostReady) {
            hostSeenAt = Date.now();
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

    const probe = async function () {
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

    const openHostSynchronously = function () {
        const features = [
            'popup=yes',
            'width=410',
            'height=170',
            'resizable=yes',
            'scrollbars=no'
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
            const isHost =
                popup.document?.documentElement
                    ?.dataset?.impeTelephonyHost === '1';

            if (!isHost) {
                popup.location.replace(hostUrl);
            }
        } catch (error) {
            // Si temporalmente no se puede inspeccionar, se conserva la ventana nombrada.
        }

        setTimeout(function () {
            try {
                popup.blur();
                window.focus();
            } catch (error) {
                // Algunos navegadores no permiten controlar el foco.
            }
        }, 120);

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

    const startCall = async function (payload) {
        await prepare({ openHost: true });

        if (state?.active) {
            throw new Error(
                'Ya existe una llamada activa.'
            );
        }

        sendCommand('START', payload || {});
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

    const clearFinished = function (callToken) {
        sendCommand(
            'CLEAR_FINISHED',
            { callToken: String(callToken || '') }
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
                    '<span data-phone-eyebrow>LLAMADA EN CURSO</span>' +
                    '<strong data-phone-title>Telefonía</strong>' +
                    '<small data-phone-number>—</small>' +
                '</div>' +
                '<button type="button" class="persistent-phone-open" data-phone-open title="Abrir llamada" aria-label="Abrir llamada">' +
                    '<i class="bi bi-arrows-angle-expand"></i>' +
                '</button>' +
            '</header>' +
            '<div class="persistent-phone-state">' +
                '<span data-phone-status>Preparando…</span>' +
                '<strong data-phone-timer>00:00</strong>' +
            '</div>' +
            '<div class="persistent-phone-actions" data-phone-active-actions>' +
                '<button type="button" class="persistent-phone-action" data-phone-mute>' +
                    '<i class="bi bi-mic-mute"></i><span>Silenciar</span>' +
                '</button>' +
                '<button type="button" class="persistent-phone-action is-danger" data-phone-hangup>' +
                    '<i class="bi bi-telephone-x"></i><span>Colgar</span>' +
                '</button>' +
            '</div>' +
            '<button type="button" class="persistent-phone-result" data-phone-result hidden>' +
                '<i class="bi bi-journal-check"></i>' +
                '<span>Registrar resultado</span>' +
            '</button>';

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
            '[data-phone-mute]'
        )?.addEventListener(
            'click',
            toggleMute
        );

        panel.querySelector(
            '[data-phone-hangup]'
        )?.addEventListener(
            'click',
            hangup
        );

        panel.querySelector(
            '[data-phone-open]'
        )?.addEventListener(
            'click',
            function () {
                openContext(false);
            }
        );

        panel.querySelector(
            '[data-phone-result]'
        )?.addEventListener(
            'click',
            function () {
                openContext(true);
            }
        );
    };

    function renderPanel() {
        if (!panel) {
            return;
        }

        const show =
            Boolean(state?.active) ||
            String(state?.phase || '') ===
                'finished';

        panel.hidden = !show;

        if (!show) {
            return;
        }

        const title =
            panel.querySelector(
                '[data-phone-title]'
            );
        const number =
            panel.querySelector(
                '[data-phone-number]'
            );
        const status =
            panel.querySelector(
                '[data-phone-status]'
            );
        const timer =
            panel.querySelector(
                '[data-phone-timer]'
            );
        const eyebrow =
            panel.querySelector(
                '[data-phone-eyebrow]'
            );
        const mute =
            panel.querySelector(
                '[data-phone-mute]'
            );
        const hangupButton =
            panel.querySelector(
                '[data-phone-hangup]'
            );
        const activeActions =
            panel.querySelector(
                '[data-phone-active-actions]'
            );
        const resultButton =
            panel.querySelector(
                '[data-phone-result]'
            );
        const live =
            panel.querySelector(
                '.persistent-phone-live'
            );

        if (title) {
            title.textContent =
                state.institution ||
                state.destination ||
                'Telefonía';
        }

        if (number) {
            number.textContent =
                state.destination ||
                (
                    state.extension
                        ? 'Extensión ' +
                            state.extension
                        : '—'
                );
        }

        if (status) {
            status.textContent =
                statusText();
        }

        if (timer) {
            timer.textContent =
                formatDuration(
                    currentDuration()
                );
        }

        const finished =
            String(state.phase || '') ===
                'finished';

        if (eyebrow) {
            eyebrow.textContent = finished
                ? 'LLAMADA FINALIZADA'
                : 'LLAMADA EN CURSO';
        }

        live?.classList.toggle(
            'is-finished',
            finished
        );

        if (activeActions) {
            activeActions.hidden =
                !state.active;
        }

        if (resultButton) {
            resultButton.hidden =
                !finished;
        }

        if (mute) {
            mute.disabled =
                !state.active ||
                String(state.status || '') !==
                    'in-progress';
            mute.classList.toggle(
                'is-active',
                Boolean(state.muted)
            );
            mute.innerHTML = state.muted
                ? '<i class="bi bi-mic"></i><span>Activar micrófono</span>'
                : '<i class="bi bi-mic-mute"></i><span>Silenciar</span>';
        }

        if (hangupButton) {
            hangupButton.disabled =
                !state.active ||
                String(state.status || '') ===
                    'finishing';
        }

        setTimeout(clampPanel, 0);
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

    addEventListener(
        'resize',
        clampPanel
    );

    window.IMPE_TELEPHONY_PERSISTENT = {
        probe: probe,
        prepare: prepare,
        startCall: startCall,
        hangup: hangup,
        toggleMute: toggleMute,
        clearFinished: clearFinished,
        subscribe: subscribe,
        getState: getState,
        openContext: openContext,
        currentUrlWithoutPhoneParams:
            currentUrlWithoutPhoneParams
    };

    document.addEventListener(
        'DOMContentLoaded',
        function () {
            createPanel();
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
                state?.phase === 'finished'
            ) {
                sendCommand('PING', {});
            }
        }
    );
})();
