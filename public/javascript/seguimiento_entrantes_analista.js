/**
 * Clasificación de llamadas entrantes de Vinculación.
 * El cliente nunca inventa duración, número ni identidad de la llamada:
 * el servidor vuelve a obtener todo de los webhooks firmados.
 */
(() => {
    'use strict';
    const page = document.querySelector('[data-linkage-incoming]');
    const form = document.querySelector('[data-incoming-form]');
    const modalEl = document.getElementById('modalLlamadaEntranteAnalista');
    if (!page || !form || !modalEl || !window.bootstrap) return;

    const modal = new bootstrap.Modal(modalEl);
    const decision = form.querySelector('[data-incoming-decision]');
    const existing = form.querySelector('[data-incoming-existing]');
    const fresh = form.querySelector('[data-incoming-new]');
    const results = form.querySelector('[data-incoming-results]');
    const errorBox = form.querySelector('[data-incoming-error]');
    const lookup = form.querySelector('[data-incoming-lookup]');
    const followup = form.querySelector('[data-incoming-followup]');
    const submit = form.querySelector('[data-incoming-submit]');
    const resultInput = form.querySelector('[name="resultado"]');
    const evidence = form.querySelector('[data-incoming-evidence]');
    const sdkNotice = form.querySelector('[data-incoming-sdk-notice]');
    const institutionConfirmed = form.querySelector('[data-incoming-confirmed]');
    const confirmedInstitutionName = form.querySelector('[data-incoming-confirmed-name]');
    const manualInstitutionSearch = form.querySelector('[data-incoming-existing-search]');
    const userId = Number(page.dataset.currentUser ||
        window.IMPE_CURRENT_USER_ID || 0);
    const resetConfirmed = () => {
        if (institutionConfirmed) institutionConfirmed.hidden = true;
        if (manualInstitutionSearch) manualInstitutionSearch.hidden = false;
    };
    form.querySelector('[data-incoming-change-expediente]')?.addEventListener(
        'click', () => {
            resetConfirmed();
            lookup.focus();
            lookup.select();
        }
    );
    const sdkConfirm = form.querySelector('[data-incoming-sdk-confirm]');
    const sdkCheck = sdkConfirm.querySelector('input[type="checkbox"]');
    let isSdkCall = false;
    const discard = form.querySelector('[data-incoming-discard]');
    const discardToggle = form.querySelector('[data-incoming-discard-toggle]');
    const discardReason = form.querySelector('[data-incoming-discard-reason]');
    const discardText = form.querySelector('[name="motivo_descarte"]');
    const searchUrl = page.dataset.searchUrl || '';
    const saveUrl = page.dataset.saveUrl || '';
    let searchTimer = null;
    let searchSequence = 0;
    let isSubmitting = false;
    let hasAnswer = true;

    const showError = (message) => {
        errorBox.textContent = String(message || 'No fue posible guardar la llamada.');
        errorBox.classList.remove('d-none');
    };
    const clearError = () => {
        errorBox.textContent = '';
        errorBox.classList.add('d-none');
    };

    const renderEvidence = () => {
        const enabled = decision.value !== 'sin_identificar';
        const result = String(resultInput.value || '');
        const allowEvidence = !isSdkCall && enabled && [
            'CONTACTO_CORRECTO', 'SOLICITO_INFORMACION',
            'SOLICITO_LLAMAR_DESPUES', 'NO_INTERESADO'
        ].includes(result);
        evidence.hidden = !allowEvidence;
        if (!allowEvidence) {
            evidence.querySelectorAll('input[type="checkbox"]')
                .forEach(input => { input.checked = false; });
        }
        discard.hidden = !enabled || result !== 'NO_INTERESADO';
        if (discard.hidden) discardToggle.checked = false;
        discardReason.hidden = discard.hidden || !discardToggle.checked;
        discardText.required = !discardReason.hidden;
        if (discardReason.hidden) discardText.value = '';
    };
    resultInput.addEventListener('change', renderEvidence);
    discardToggle.addEventListener('change', renderEvidence);

    const renderDecision = () => {
        const value = decision.value;
        existing.hidden = value !== 'existente';
        fresh.hidden = value !== 'nueva';
        results.hidden = value === 'sin_identificar';
        const followupRequired = value === 'existente';
        followup.required = followupRequired;
        form.querySelector('[name="nombre_entidad"]').required = value === 'nueva';
        form.querySelector('[name="estado_id"]').required = value === 'nueva';
        form.querySelector('[name="resultado"]').required = value !== 'sin_identificar';
        sdkConfirm.hidden = !isSdkCall || value === 'sin_identificar';
        sdkCheck.required = !sdkConfirm.hidden;
        if (sdkConfirm.hidden) sdkCheck.checked = false;
        renderEvidence();
        clearError();
    };
    decision.addEventListener('change', () => {
        resetConfirmed();
        renderDecision();
    });

    const searchFollowups = async (query, seq) => {
        if (query.trim().length < 2) {
            followup.replaceChildren(new Option('Escribe al menos dos caracteres', ''));
            return;
        }
        const uri = new URL(searchUrl, window.location.href);
        uri.searchParams.set('q', query.trim());
        const res = await fetch(uri, {
            credentials: 'same-origin', cache: 'no-store',
            headers: {Accept: 'application/json'}
        });
        const data = await res.json();
        if (seq !== searchSequence) return;
        if (!res.ok || !data.ok) throw new Error(data.mensaje || 'No fue posible buscar.');
        followup.replaceChildren(new Option('Selecciona un expediente', ''));
        for (const row of (data.resultados || [])) {
            const option = new Option(
                String(row.nombre_entidad || 'Institución') +
                    ' · ' + String(row.estado_nombre || ''),
                String(row.id || '')
            );
            followup.add(option);
        }
        if (!data.resultados?.length) {
            followup.replaceChildren(new Option('No se encontraron expedientes', ''));
        }
    };
    lookup.addEventListener('input', () => {
        const seq = ++searchSequence;
        clearTimeout(searchTimer);
        searchTimer = setTimeout(() => {
            searchFollowups(lookup.value, seq).catch(err => {
                if (seq === searchSequence) showError(err.message);
            });
        }, 250);
    });

    const openRegistration = button => {
        if (!button) return;
        form.reset();
        clearError();
        resetConfirmed();
        const id = String(button.dataset.callId || '');
        isSdkCall = button.dataset.source === 'SDK_WEBRTC';
        form.querySelector('[data-incoming-pbx]').value = isSdkCall ? '' : id;
        form.querySelector('[data-incoming-sdk-id]').value = isSdkCall ? id : '';
        sdkNotice.hidden = !isSdkCall;
        form.querySelector('[data-incoming-number]').textContent =
            String(button.dataset.phone || 'Número privado');
        let durationSeconds = 0;
        const listedDuration = String(button.dataset.duration || '00:00');
        const parts = /^(\\d{1,3}):(\\d{2})$/.exec(listedDuration);
        if (parts) durationSeconds = Number(parts[1]) * 60 + Number(parts[2]);
        // Un evento UTC recibido tarde puede registrar 00:01 aunque el
        // cronómetro de la sesión haya observado 00:19. Mostrar el tiempo
        // de esta llamada exacta, no arrastrar el de otra sesión.
        if (isSdkCall && userId > 0 && id) {
            try {
                const key = 'impe:crm:call-summary:' + userId + ':' + id;
                const summary = JSON.parse(sessionStorage.getItem(key) || 'null');
                if (summary?.call_id === id &&
                    Date.now() - Number(summary.saved_at || 0) < 86400000) {
                    durationSeconds = Math.max(durationSeconds,
                        Math.floor(Number(summary.duration || 0)));
                }
            } catch (_) {}
        }
        const mm = String(Math.floor(durationSeconds / 60)).padStart(2,'0');
        const ss = String(durationSeconds % 60).padStart(2,'0');
        form.querySelector('[data-incoming-duration]').textContent = mm + ':' + ss;
        followup.replaceChildren(new Option('Busca un expediente', ''));
        lookup.value = '';
        ++searchSequence;
        clearTimeout(searchTimer);
        hasAnswer = button.dataset.answer === '1';
        decision.value = hasAnswer ? 'existente' : 'sin_identificar';
        for (const option of Array.from(decision.options)) {
            if (option.value !== 'sin_identificar') option.disabled = !hasAnswer;
        }
        modalEl.querySelector('[data-incoming-modal-description]').textContent = hasAnswer
            ? 'Vincula la conversación a un expediente y registra su resultado.'
            : 'Llamada sin respuesta: registra su clasificación en el historial.';
        renderDecision();
        // Si Recepción identificó el expediente, precargarlo por ID exacto
        // de esta sesión. El servidor volverá a validar la pertenencia al
        // Analista al guardar; nunca se asume que teléfono = identidad.
        if (isSdkCall && id) {
            try {
                const key = 'impe:crm:transfer-context:' + userId + ':' + id;
                const context = JSON.parse(sessionStorage.getItem(key) || 'null');
                if (context && context.call_id === id &&
                    Number(context.seguimiento_id) > 0 &&
                    Date.now() - Number(context.saved_at || 0) < 180000) {
                    decision.value = 'existente';
                    lookup.value = String(context.institucion || '');
                    followup.replaceChildren(new Option(
                        String(context.institucion || 'Institución') +
                        ' · ' + String(context.estado || ''),
                        String(context.seguimiento_id)
                    ));
                    renderDecision();
                    if (institutionConfirmed) institutionConfirmed.hidden = false;
                    if (confirmedInstitutionName) {
                        confirmedInstitutionName.textContent =
                            String(context.institucion || 'Institución') + ' · ' +
                            String(context.estado || '');
                    }
                    if (manualInstitutionSearch) manualInstitutionSearch.hidden = true;
                    modalEl.querySelector('[data-incoming-modal-description]').textContent =
                        'Expediente identificado por Recepción. Captura el resultado de la conversación.';
                }
            } catch (_) {}
        }
        // El borrador capturado DURANTE esta misma llamada desde el shell
        // se importa únicamente por ID exacto y usuario actual. Nunca se
        // asigna automáticamente al expediente de otra llamada.
        try {
            if (userId > 0 && id) {
                const key = 'impe:crm:call-draft:' + userId + ':' + id;
                const draft = JSON.parse(sessionStorage.getItem(key) || 'null');
                if (draft && draft.call_id === id &&
                    Date.now() - Number(draft.saved_at || 0) < 86400000) {
                    const names = {
                        nombre:'nombre_entidad',
                        persona:'persona_atendio',
                        telefono:'telefono_capturado',
                        correo:'correo_capturado',
                        notas:'observacion'
                    };
                    Object.entries(names).forEach(([from, to]) => {
                        const input = form.elements.namedItem(to);
                        if (input && !input.value) input.value =
                            String(draft[from] || '');
                    });
                }
            }
        } catch (_) { /* La ficha sigue siendo editable sin borrador. */ }
        modal.show();
    };

    // Reproductor de tarjetas: mismos controles y clases visuales del
    // expediente, sin audio nativo desproporcionado dentro del listado.
    const formatAudioTime = seconds => {
        if (!Number.isFinite(seconds) || seconds < 0) return '0:00';
        const total = Math.floor(seconds);
        const minutes = Math.floor(total / 60);
        return minutes + ':' + String(total % 60).padStart(2, '0');
    };

    const wirePlayer = panel => {
        if (panel.dataset.incomingPlayerReady === '1') return;
        const custom = panel.querySelector('[data-incoming-player]');
        const audio = custom?.querySelector('audio');
        const play = custom?.querySelector('[data-incoming-play]');
        const iconPlay = play?.querySelector('i');
        const elapsed = custom?.querySelector('[data-incoming-time]');
        const duration = custom?.querySelector('[data-incoming-total]');
        const progress = custom?.querySelector('[data-incoming-progress]');
        const volume = custom?.querySelector('[data-incoming-volume]');
        const iconVolume = volume?.querySelector('i');
        const error = panel.querySelector('[data-incoming-player-error]');
        if (!audio || !play || !elapsed || !duration || !progress || !volume) return;
        panel.dataset.incomingPlayerReady = '1';

        const updatePlay = () => {
            const playing = !audio.paused && !audio.ended;
            play.setAttribute('aria-label', playing ? 'Pausar grabación' : 'Reproducir grabación');
            play.setAttribute('aria-pressed', String(playing));
            if (iconPlay) iconPlay.className = 'bi ' + (playing ? 'bi-pause-fill' : 'bi-play-fill');
            custom.classList.toggle('is-loading', audio.readyState < 3 && !audio.paused);
        };
        const updateTime = () => {
            const durationSeconds = Number.isFinite(audio.duration) ? audio.duration : 0;
            elapsed.textContent = formatAudioTime(audio.currentTime);
            if (durationSeconds > 0) duration.textContent = formatAudioTime(durationSeconds);
            const position = durationSeconds > 0 ? Math.min(100, 100 * audio.currentTime / durationSeconds) : 0;
            progress.value = String(position);
            progress.style.setProperty('--player-progress', position + '%');
            progress.setAttribute('aria-valuetext', elapsed.textContent + ' de ' + duration.textContent);
        };
        const errorMessage = message => {
            error.hidden = false;
            error.textContent = message;
            custom.classList.remove('is-loading');
        };
        audio.addEventListener('loadedmetadata', updateTime);
        audio.addEventListener('durationchange', updateTime);
        audio.addEventListener('timeupdate', updateTime);
        audio.addEventListener('play', updatePlay);
        audio.addEventListener('pause', updatePlay);
        audio.addEventListener('ended', updatePlay);
        audio.addEventListener('playing', () => {
            error.hidden = true;
            error.textContent = '';
            custom.classList.remove('is-loading');
            updatePlay();
        });
        audio.addEventListener('waiting', () => custom.classList.add('is-loading'));
        audio.addEventListener('error', () => {
            errorMessage('No se pudo cargar el audio. Comprueba tu sesión o intenta nuevamente.');
            updatePlay();
        });
        play.addEventListener('click', async () => {
            if (!audio.paused && !audio.ended) {
                audio.pause();
                return;
            }
            // Una sola grabación audible a la vez.
            page.querySelectorAll('[data-incoming-player] audio').forEach(other => {
                if (other !== audio && !other.paused) other.pause();
            });
            error.hidden = true;
            error.textContent = '';
            custom.classList.add('is-loading');
            try {
                await audio.play();
            } catch (_) {
                errorMessage('No fue posible reproducir la grabación. Puedes volver a intentarlo.');
                updatePlay();
            }
        });
        progress.addEventListener('input', () => {
            if (Number.isFinite(audio.duration) && audio.duration > 0) {
                audio.currentTime = Number(progress.value) * audio.duration / 100;
            }
            updateTime();
        });
        volume.addEventListener('click', () => {
            audio.muted = !audio.muted;
            volume.setAttribute('aria-pressed', String(audio.muted));
            volume.setAttribute('aria-label', audio.muted ? 'Activar sonido' : 'Silenciar grabación');
            if (iconVolume) iconVolume.className = 'bi ' +
                (audio.muted ? 'bi-volume-mute' : 'bi-volume-up');
        });
        updateTime();
        updatePlay();
    };

    page.addEventListener('click', event => {
        const button = event.target.closest('[data-incoming-audio-toggle]');
        if (!button || !page.contains(button)) return;
        const panelId = button.getAttribute('aria-controls');
        const panel = panelId ? document.getElementById(panelId) : null;
        if (!panel || !page.contains(panel)) return;
        const wasClosed = panel.hidden;
        page.querySelectorAll('[data-incoming-audio-panel]').forEach(otherPanel => {
            if (otherPanel === panel) return;
            otherPanel.hidden = true;
            const otherButton = page.querySelector('[aria-controls="' + otherPanel.id + '"]');
            if (otherButton) {
                otherButton.setAttribute('aria-expanded', 'false');
                otherButton.classList.remove('is-open');
            }
            const otherAudio = otherPanel.querySelector('audio');
            if (otherAudio && !otherAudio.paused) otherAudio.pause();
        });
        panel.hidden = !wasClosed;
        button.setAttribute('aria-expanded', String(wasClosed));
        button.classList.toggle('is-open', wasClosed);
        if (wasClosed) {
            wirePlayer(panel);
        } else {
            const audio = panel.querySelector('audio');
            if (audio && !audio.paused) audio.pause();
        }
    });

    page.addEventListener('click', async event => {
        const button = event.target.closest('[data-incoming-sync-audio]');
        if (!button || !page.contains(button) || button.disabled) return;
        const sessionId = String(button.dataset.incomingSyncAudio || '').trim();
        const status = button.parentElement?.querySelector('[role="status"]');
        if (!/^sdk-[a-z0-9-]{12,94}$/.test(sessionId)) return;
        const endpoint = String(page.dataset.audioSyncUrl || '');
        if (!endpoint) return;
        const original = button.innerHTML;
        button.disabled = true;
        button.textContent = 'Buscando grabación…';
        if (status) status.textContent = '';
        try {
            const body = new URLSearchParams({
                csrf_token: String(form.querySelector('[name="csrf_token"]')?.value || ''),
                session_id: sessionId
            });
            const res = await fetch(endpoint, {
                method: 'POST',
                credentials: 'same-origin',
                cache: 'no-store',
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'fetch',
                    'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8'
                },
                body: body.toString()
            });
            const data = await res.json();
            if (!res.ok || data.ok !== true) {
                throw new Error(data.mensaje || 'No se encontró un audio verificable.');
            }
            if (status) status.textContent = 'Grabación lista. Actualizando historial…';
            window.location.reload();
        } catch (error) {
            if (status) {
                status.textContent = error.message ||
                    'No fue posible recuperar la grabación. Inténtalo después.';
            }
        } finally {
            button.disabled = false;
            button.innerHTML = original;
        }
    });

    page.addEventListener('click', event => {
        const button = event.target.closest('[data-call-id]');
        if (button && page.contains(button)) openRegistration(button);
    });

    form.addEventListener('submit', async event => {
        event.preventDefault();
        if (isSubmitting) return;
        clearError();
        if (!form.reportValidity()) return;
        isSubmitting = true;
        submit.disabled = true;
        const original = submit.innerHTML;
        submit.textContent = 'Guardando…';
        try {
            const res = await fetch(saveUrl, {
                method: 'POST', credentials: 'same-origin',
                headers: {'Accept': 'application/json', 'X-Requested-With': 'fetch'},
                body: new FormData(form)
            });
            const data = await res.json();
            if (!res.ok || !data.ok) {
                throw new Error(data.mensaje || 'No fue posible registrar la llamada.');
            }
            try {
                const id = String(
                    form.querySelector('[data-incoming-sdk-id]')?.value ||
                    form.querySelector('[data-incoming-pbx]')?.value || '');
                if (id && userId) {
                    sessionStorage.removeItem(
                        'impe:crm:call-draft:' + userId + ':' + id);
                    sessionStorage.removeItem(
                        'impe:crm:call-summary:' + userId + ':' + id);
                    sessionStorage.removeItem(
                        'impe:crm:transfer-context:' + userId + ':' + id);
                }
            } catch (_) {}
            modal.hide();
            // Unicamente después del OK de MySQL avisamos al shell que
            // puede retirar la obligación visual de registrar resultado.
            const confirmedId = String(
                form.querySelector('[data-incoming-sdk-id]')?.value ||
                form.querySelector('[data-incoming-pbx]')?.value || ''
            );
            if (confirmedId && window.parent !== window) {
                window.parent.postMessage({
                    type:'IMPE_ANALYST_RESULT_SAVED',
                    callId:confirmedId
                }, window.location.origin);
            }
            // Sólo el servidor decide a qué expediente pertenece.
            // Para existente o nueva, abrirlo inmediatamente tras guardar.
            const linked = Number(data.resultado?.seguimiento_id || 0);
            const target = linked > 0
                ? 'index.php?controller=seguimientoVinculacion&action=detalle&id=' + linked
                : 'index.php?controller=seguimientoVinculacion&action=llamadasEntrantes';
            window.location.assign(new URL(target, window.location.href).toString());
        } catch (error) {
            showError(error.message || 'No fue posible guardar. Intenta nuevamente.');
        } finally {
            submit.disabled = false;
            submit.innerHTML = original;
            isSubmitting = false;
        }
    });

    renderDecision();
    const focusId = String(page.dataset.autoCall || '').trim();
    if (focusId) {
        const found = Array.from(page.querySelectorAll('[data-call-id]'))
            .find(el => el.dataset.callId === focusId);
        if (found) window.setTimeout(() => openRegistration(found), 100);
    }
})();
