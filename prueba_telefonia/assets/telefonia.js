(() => {
    'use strict';

    const els = {
        statusDot: document.getElementById('status-dot'),
        deviceStatus: document.getElementById('device-status'),
        phoneNumber: document.getElementById('phone-number'),
        callLabel: document.getElementById('call-label'),
        callTimer: document.getElementById('call-timer'),
        volumeWrap: document.getElementById('volume-wrap'),
        micMeter: document.getElementById('mic-meter'),
        speakerMeter: document.getElementById('speaker-meter'),
        btnInit: document.getElementById('btn-init'),
        btnCall: document.getElementById('btn-call'),
        btnMute: document.getElementById('btn-mute'),
        btnHangup: document.getElementById('btn-hangup'),
        btnRefresh: document.getElementById('btn-refresh'),
        eventLog: document.getElementById('event-log'),
        callsBody: document.getElementById('calls-body'),
        recordingsList: document.getElementById('recordings-list'),
    };

    let device = null;
    let activeCall = null;
    let timerInterval = null;
    let timerStartedAt = null;
    let muted = false;
    let activeParentSid = null;
    let statusPollInterval = null;
    let statusPollBusy = false;
    let conversationStarted = false;
    let lastChildStatus = null;

    function log(message, type = 'info') {
        const row = document.createElement('div');
        row.className = `log-row log-${type}`;
        row.textContent = `${new Date().toLocaleTimeString('es-MX')} · ${message}`;
        els.eventLog.prepend(row);
    }

    function setDeviceState(text, state = 'idle') {
        els.deviceStatus.textContent = text;
        els.statusDot.dataset.state = state;
    }

    function formatDuration(seconds) {
        const total = Math.max(0, Number(seconds) || 0);
        const minutes = Math.floor(total / 60);
        const secs = total % 60;
        return `${String(minutes).padStart(2, '0')}:${String(secs).padStart(2, '0')}`;
    }

    function startTimer() {
        if (timerInterval || timerStartedAt) {
            return;
        }

        timerStartedAt = Date.now();
        els.callTimer.textContent = '00:00';
        timerInterval = window.setInterval(() => {
            const elapsed = Math.floor((Date.now() - timerStartedAt) / 1000);
            els.callTimer.textContent = formatDuration(elapsed);
        }, 500);
    }

    function stopTimer(reset = false) {
        if (timerInterval) {
            clearInterval(timerInterval);
            timerInterval = null;
        }
        timerStartedAt = null;
        if (reset) {
            els.callTimer.textContent = '00:00';
        }
    }

    function stopStatusPolling() {
        if (statusPollInterval) {
            clearInterval(statusPollInterval);
            statusPollInterval = null;
        }
        statusPollBusy = false;
    }

    async function fetchChildCall(parentSid) {
        const response = await fetch(`api/estado_llamada.php?parent_sid=${encodeURIComponent(parentSid)}`, {
            headers: { 'X-Requested-With': 'fetch' },
            credentials: 'same-origin',
            cache: 'no-store',
        });
        const data = await response.json();

        if (!response.ok || !data.ok) {
            throw new Error(data.mensaje || 'No se pudo consultar el estado real de la llamada.');
        }

        return data.call || null;
    }

    function applyChildCallState(childCall) {
        if (!childCall) {
            return;
        }

        const status = String(childCall.status || '');
        const statusChanged = status !== lastChildStatus;
        lastChildStatus = status;

        if (status === 'queued') {
            els.callLabel.textContent = 'Preparando llamada…';
            return;
        }

        if (status === 'ringing') {
            els.callLabel.textContent = 'Timbrando…';
            setDeviceState('Timbrando', 'busy');
            if (statusChanged) {
                log('El teléfono destino está timbrando.');
            }
            return;
        }

        if (status === 'in-progress') {
            els.callLabel.textContent = 'Llamada en curso';
            setDeviceState('En llamada', 'busy');
            els.btnMute.disabled = false;

            if (!conversationStarted) {
                conversationStarted = true;
                startTimer();
                log('El destino contestó. Inicia el tiempo de conversación.', 'success');
            }
            return;
        }

        if (status === 'completed') {
            if (Number(childCall.duration) > 0) {
                els.callTimer.textContent = formatDuration(childCall.duration);
            }
            return;
        }

        const terminalLabels = {
            busy: 'Línea ocupada',
            'no-answer': 'Sin respuesta',
            failed: 'Llamada fallida',
            canceled: 'Llamada cancelada',
        };

        if (terminalLabels[status]) {
            els.callLabel.textContent = terminalLabels[status];
        }
    }

    async function checkCallStatus(parentSid) {
        if (!parentSid || statusPollBusy) {
            return;
        }

        statusPollBusy = true;
        try {
            const childCall = await fetchChildCall(parentSid);
            applyChildCallState(childCall);
        } catch (error) {
            // La consulta es complementaria al audio. No se interrumpe la llamada si falla temporalmente.
            console.warn(error);
        } finally {
            statusPollBusy = false;
        }
    }

    function startStatusPolling(parentSid) {
        stopStatusPolling();
        activeParentSid = parentSid;
        lastChildStatus = null;
        checkCallStatus(parentSid);
        statusPollInterval = window.setInterval(() => checkCallStatus(parentSid), 800);
    }

    async function syncFinalDuration(parentSid, attempt = 0) {
        if (!parentSid) {
            return;
        }

        try {
            const childCall = await fetchChildCall(parentSid);
            if (childCall && Number(childCall.duration) > 0) {
                els.callTimer.textContent = formatDuration(childCall.duration);
                return;
            }

            const terminal = childCall && ['completed', 'busy', 'no-answer', 'failed', 'canceled'].includes(childCall.status);
            if (terminal) {
                if (!conversationStarted) {
                    els.callTimer.textContent = '00:00';
                }
                return;
            }
        } catch (error) {
            console.warn(error);
        }

        if (attempt < 6) {
            window.setTimeout(() => syncFinalDuration(parentSid, attempt + 1), 800);
        }
    }

    function validE164(value) {
        return /^\+[1-9]\d{7,14}$/.test(String(value || '').trim());
    }

    function resetCallUi() {
        activeCall = null;
        muted = false;
        els.btnMute.textContent = 'Silenciar';
        els.btnMute.disabled = true;
        els.btnHangup.disabled = true;
        els.btnCall.disabled = !device;
        els.volumeWrap.hidden = true;
        els.micMeter.style.width = '0%';
        els.speakerMeter.style.width = '0%';
        els.callLabel.textContent = 'Llamada finalizada';
        stopTimer(false);
        stopStatusPolling();
        activeParentSid = null;
        lastChildStatus = null;
    }

    function bindCall(call) {
        activeCall = call;
        conversationStarted = false;
        els.btnCall.disabled = true;
        els.btnMute.disabled = true;
        els.btnHangup.disabled = false;
        els.callLabel.textContent = 'Conectando con Twilio…';
        els.volumeWrap.hidden = false;
        log('Twilio está preparando la llamada.');

        call.on('ringing', () => {
            els.callLabel.textContent = 'Timbrando…';
            setDeviceState('Timbrando', 'busy');
        });

        call.on('accept', () => {
            const parentSid = call.parameters?.CallSid
                || call.parameters?.CallSID
                || call.outboundConnectionId
                || '';

            els.callLabel.textContent = 'Marcando al destino…';
            setDeviceState('Marcando', 'busy');
            log('Conexión con Twilio establecida. Esperando respuesta del destino.', 'success');

            if (parentSid) {
                startStatusPolling(parentSid);
            } else {
                log('No se obtuvo el Call SID para sincronizar el tiempo real.', 'error');
            }
        });

        call.on('volume', (inputVolume, outputVolume) => {
            els.micMeter.style.width = `${Math.min(100, Math.round(inputVolume * 100))}%`;
            els.speakerMeter.style.width = `${Math.min(100, Math.round(outputVolume * 100))}%`;
        });

        const finish = (label) => {
            const parentSid = activeParentSid;
            log(label);
            setDeviceState('Teléfono listo', 'ready');
            resetCallUi();
            syncFinalDuration(parentSid);
            window.setTimeout(refreshHistory, 3000);
        };

        call.on('disconnect', () => finish('La llamada terminó.'));
        call.on('cancel', () => finish('La llamada fue cancelada.'));
        call.on('reject', () => finish('La llamada fue rechazada.'));
        call.on('error', (error) => {
            log(`Error en llamada: ${error.message}`, 'error');
        });
    }

    async function initializeDevice() {
        els.btnInit.disabled = true;
        setDeviceState('Solicitando micrófono…', 'loading');

        try {
            await navigator.mediaDevices.getUserMedia({ audio: true });

            const response = await fetch('api/token.php', {
                headers: { 'X-Requested-With': 'fetch' },
                credentials: 'same-origin',
            });
            const data = await response.json();

            if (!response.ok || !data.ok || !data.token) {
                throw new Error(data.mensaje || 'No fue posible obtener el token de Twilio.');
            }

            device = new Twilio.Device(data.token, { logLevel: 1 });

            device.on('registered', () => {
                setDeviceState('Teléfono listo', 'ready');
                els.btnCall.disabled = false;
                els.btnInit.textContent = 'Teléfono iniciado';
                log(`Dispositivo listo${data.identity ? ` (${data.identity})` : ''}.`, 'success');
            });

            device.on('unregistered', () => {
                setDeviceState('Teléfono desconectado', 'idle');
                els.btnCall.disabled = true;
            });

            device.on('error', (error) => {
                setDeviceState('Error de dispositivo', 'error');
                log(`Twilio Device: ${error.message}`, 'error');
            });

            await device.register();
        } catch (error) {
            setDeviceState('No se pudo iniciar', 'error');
            els.btnInit.disabled = false;
            log(error.message || 'No se pudo iniciar el teléfono.', 'error');
        }
    }

    async function makeCall() {
        const to = els.phoneNumber.value.trim();

        if (!device) {
            log('Primero inicia el teléfono.', 'error');
            return;
        }

        if (!validE164(to)) {
            log('Escribe el número en formato internacional, por ejemplo +52XXXXXXXXXX.', 'error');
            els.phoneNumber.focus();
            return;
        }

        try {
            conversationStarted = false;
            els.callLabel.textContent = `Marcando a ${to}`;
            els.callTimer.textContent = '00:00';
            const call = await device.connect({ params: { To: to } });
            bindCall(call);
        } catch (error) {
            log(`No se pudo iniciar la llamada: ${error.message}`, 'error');
            resetCallUi();
        }
    }

    function toggleMute() {
        if (!activeCall) {
            return;
        }

        muted = !muted;
        activeCall.mute(muted);
        els.btnMute.textContent = muted ? 'Activar micrófono' : 'Silenciar';
        log(muted ? 'Micrófono silenciado.' : 'Micrófono activado.');
    }

    function hangup() {
        if (!activeCall) {
            return;
        }

        els.btnHangup.disabled = true;
        els.callLabel.textContent = 'Finalizando…';
        activeCall.disconnect();
    }

    function escapeHtml(value) {
        return String(value ?? '')
            .replaceAll('&', '&amp;')
            .replaceAll('<', '&lt;')
            .replaceAll('>', '&gt;')
            .replaceAll('"', '&quot;')
            .replaceAll("'", '&#039;');
    }

    function formatDate(value) {
        if (!value) {
            return '—';
        }
        const date = new Date(value);
        return Number.isNaN(date.getTime()) ? String(value) : date.toLocaleString('es-MX');
    }

    function statusLabel(status) {
        const labels = {
            completed: 'Completada',
            busy: 'Ocupado',
            failed: 'Fallida',
            'no-answer': 'Sin respuesta',
            canceled: 'Cancelada',
            queued: 'En cola',
            ringing: 'Timbrando',
            'in-progress': 'En curso',
        };
        return labels[status] || status || '—';
    }

    async function refreshHistory() {
        els.btnRefresh.disabled = true;

        try {
            const response = await fetch('api/historial.php', {
                headers: { 'X-Requested-With': 'fetch' },
                credentials: 'same-origin',
            });
            const data = await response.json();

            if (!response.ok || !data.ok) {
                throw new Error(data.mensaje || 'No se pudo cargar el historial.');
            }

            if (!data.calls.length) {
                els.callsBody.innerHTML = '<tr><td colspan="4" class="empty">Todavía no hay llamadas salientes para mostrar.</td></tr>';
            } else {
                els.callsBody.innerHTML = data.calls.map((call) => `
                    <tr>
                        <td>${escapeHtml(formatDate(call.start_time))}</td>
                        <td>${escapeHtml(call.to || '—')}</td>
                        <td><span class="status-tag status-${escapeHtml(call.status)}">${escapeHtml(statusLabel(call.status))}</span></td>
                        <td>${escapeHtml(formatDuration(call.duration))}</td>
                    </tr>
                `).join('');
            }

            if (!data.recordings.length) {
                els.recordingsList.innerHTML = '<p class="empty">Todavía no hay grabaciones disponibles.</p>';
            } else {
                els.recordingsList.innerHTML = data.recordings.map((recording) => `
                    <article class="recording-item">
                        <div class="recording-meta">
                            <strong>${escapeHtml(formatDate(recording.start_time))}</strong>
                            <span>${escapeHtml(formatDuration(recording.duration))} · ${escapeHtml(recording.channels || 1)} canal(es) · ${escapeHtml(recording.status || '')}</span>
                        </div>
                        <audio controls preload="none" src="${escapeHtml(recording.audio_url)}"></audio>
                    </article>
                `).join('');
            }
        } catch (error) {
            const message = escapeHtml(error.message || 'No se pudo consultar Twilio.');
            els.callsBody.innerHTML = `<tr><td colspan="4" class="empty error-text">${message}</td></tr>`;
            els.recordingsList.innerHTML = `<p class="empty error-text">${message}</p>`;
        } finally {
            els.btnRefresh.disabled = false;
        }
    }

    els.btnInit.addEventListener('click', initializeDevice);
    els.btnCall.addEventListener('click', makeCall);
    els.btnMute.addEventListener('click', toggleMute);
    els.btnHangup.addEventListener('click', hangup);
    els.btnRefresh.addEventListener('click', refreshHistory);
    els.phoneNumber.addEventListener('keydown', (event) => {
        if (event.key === 'Enter' && !els.btnCall.disabled) {
            event.preventDefault();
            makeCall();
        }
    });

    window.addEventListener('beforeunload', () => {
        stopStatusPolling();
        if (device) {
            device.destroy();
        }
    });

    refreshHistory();
})();
