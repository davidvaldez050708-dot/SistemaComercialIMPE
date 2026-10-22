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

    const callbackMode=page.dataset.resultMode==='DEVOLUCION';
    const modal = bootstrap.Modal.getOrCreateInstance(modalEl);
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
    const municipality=form.querySelector('[data-incoming-municipality]');
    const stateInput=form.elements.namedItem('estado_id');
    const municipalityStatus=form.querySelector('[data-municipality-status]');
    let municipalitySequence=0;
    const loadMunicipalities=async(preferred='')=>{
        if(!municipality)return;
        const seq=++municipalitySequence,state=String(stateInput.value||'');
        municipality.replaceChildren(new Option(state?'Sin municipio seleccionado':'Selecciona primero un estado',''));
        municipality.disabled=!state||decision.value!=='nueva';
        if(municipalityStatus)municipalityStatus.textContent='';
        if(municipality.disabled)return;
        municipality.disabled=true;
        if(municipalityStatus)municipalityStatus.textContent='Cargando municipios…';
        try{
            const url=new URL(municipality.dataset.municipalityUrl,document.baseURI);url.searchParams.set('estado_id',state);
            const response=await fetch(url,{credentials:'same-origin',cache:'no-store',headers:{Accept:'application/json'}});
            const data=await response.json();
            if(seq!==municipalitySequence||state!==stateInput.value||decision.value!=='nueva')return;
            if(!response.ok||!data.ok)throw Error(data.mensaje||'No se pudo cargar el catálogo de municipios.');
            for(const item of data.municipios||[])municipality.add(new Option(String(item.nombre),String(item.id)));
            if(Array.from(municipality.options).some(o=>o.value===String(preferred)))municipality.value=String(preferred);
            if(municipalityStatus)municipalityStatus.textContent='';
        }catch(e){if(seq===municipalitySequence&&municipalityStatus)municipalityStatus.textContent=e.message;}
        finally{if(seq===municipalitySequence)municipality.disabled=decision.value!=='nueva';}
    };
    stateInput?.addEventListener('change',()=>void loadMunicipalities());
    const resetConfirmed = () => {
        if (institutionConfirmed) institutionConfirmed.hidden = true;
        if (manualInstitutionSearch) manualInstitutionSearch.hidden = false;
    };
    form.querySelector('[data-incoming-change-expediente]')?.addEventListener(
        'click', () => {
            resetConfirmed();
            lookup.focus();
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
    let modalCycle=0, formDirty=false, closingAfterSave=false, resultTrigger=null, draftTimer=null, interrupted=false;
    let callRefs=[];
    let resultModalToken = '';
    const releasePhone = () => {
        try { window.parent.IMPE_TELEPHONY_SHELL?.endCallResult?.(resultModalToken); } catch (_) {}
        document.body.classList.remove('impe-call-result-form-open');
    };
    modalEl.addEventListener('show.bs.modal',()=>{
        resultModalToken='call-result-'+Date.now()+'-'+Math.random().toString(36).slice(2);
        document.body.classList.add('impe-call-result-form-open');
        try { window.parent.IMPE_TELEPHONY_SHELL?.beginCallResult?.(resultModalToken,callRefs); } catch (_) {}
    });
    modalEl.addEventListener('hidden.bs.modal',releasePhone);
    window.addEventListener('pagehide',releasePhone);
    window.addEventListener('message',event=>{
        if(event.origin!==location.origin || event.source!==window.parent || event.data?.type!=='IMPE_CALL_RESULT_INTERRUPT')return;
        if(modalEl.classList.contains('show')){interrupted=true;modal.hide();}
    });
    const drafts=window.IMPE_CALL_DRAFTS;
    const csrf=()=>form.querySelector('[name="csrf_token"]').value;
    const draftStatus=form.querySelector('[data-incoming-draft-status]');
    const referred=form.querySelector('[data-incoming-referred]');
    const draftValues=()=>Object.fromEntries(Array.from(form.elements).filter(e=>e.name && !['csrf_token','pbx_call_id','sdk_session_id','sdk_reference','confirmacion_manual_sdk'].includes(e.name)).map(e=>[e.name,e.type==='checkbox'?(e.checked?'1':'0'):e.value]));
    const writeDraft=async()=>{
        if(!drafts||!callRefs.length||isSubmitting)return;
        const cycle=modalCycle,values=draftValues(),ids=[...callRefs];
        try {await drafts.save(userId,ids,values,csrf());if(cycle===modalCycle){draftStatus.hidden=false;draftStatus.textContent='Borrador guardado.';}}
        catch(_){if(cycle===modalCycle){draftStatus.hidden=false;draftStatus.textContent='Borrador conservado en este navegador; pendiente de guardar en el servidor.';}}
    };
    form.addEventListener('input',()=>{
        formDirty=true;drafts?.localSave(userId,callRefs,draftValues());clearTimeout(draftTimer);draftTimer=setTimeout(writeDraft,700);
    });
    form.addEventListener('change',()=>{
        formDirty=true;drafts?.localSave(userId,callRefs,draftValues());clearTimeout(draftTimer);draftTimer=setTimeout(writeDraft,700);
    });
    modalEl.addEventListener('hide.bs.modal',event=>{if(isSubmitting&&!closingAfterSave&&!interrupted)event.preventDefault();else if(!closingAfterSave&&formDirty)void writeDraft();});
    modalEl.addEventListener('hidden.bs.modal',()=>{++modalCycle;++searchSequence;clearTimeout(searchTimer);clearTimeout(draftTimer);if(callbackMode&&closingAfterSave)isSubmitting=false;closingAfterSave=false;interrupted=false;if(callbackMode&&!isSubmitting){form.querySelectorAll('input,select,textarea,button').forEach(e=>e.disabled=false);modalEl.querySelectorAll('[data-bs-dismiss]').forEach(e=>e.disabled=false);submit.innerHTML='<i class="bi bi-check2-circle"></i> Guardar resultado';}resultTrigger?.isConnected&&resultTrigger.focus({preventScroll:true});});
    modalEl.addEventListener('shown.bs.modal',()=>{if(interrupted){modal.hide();return;}modalEl.querySelector('.modal-body').scrollTop=0;decision.focus({preventScroll:true});});

    const showError = message => window.IMPE_TELEPHONY_NOTIFY?.(String(message || 'No fue posible guardar la llamada.'),true);
    const clearError = () => {
        errorBox.textContent = '';
        errorBox.classList.add('d-none');
    };

    const renderEvidence = () => {
        const enabled = ['existente','nueva'].includes(decision.value);
        const result = String(resultInput.value || '');
        const allowEvidence = enabled && [
            'CONTACTO_CORRECTO', 'CONTACTO_REFERIDO', 'SOLICITO_INFORMACION',
            'SOLICITO_LLAMAR_DESPUES', 'NO_INTERESADO'
        ].includes(result);
        evidence.hidden = !allowEvidence;
        referred.hidden=!enabled || result!=='CONTACTO_REFERIDO';
        referred.querySelectorAll('input').forEach(el=>el.disabled=referred.hidden);
        sdkConfirm.hidden=!isSdkCall || !allowEvidence;
        sdkCheck.required=!sdkConfirm.hidden;
        if(sdkConfirm.hidden)sdkCheck.checked=false;
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
        results.hidden = !callbackMode && value === 'sin_identificar';
        const identified=form.querySelector('[data-incoming-identified]');
        if(identified){identified.hidden=value!=='identificada';identified.querySelector('input').required=value==='identificada';}
        const followupRequired = value === 'existente';
        followup.required = followupRequired;
        form.querySelector('[name="nombre_entidad"]').required = value === 'nueva';
        form.querySelector('[name="estado_id"]').required = value === 'nueva';
        form.querySelector('[name="resultado"]').required = callbackMode || value !== 'sin_identificar';
        sdkConfirm.hidden = !isSdkCall || value === 'sin_identificar';
        sdkCheck.required = !sdkConfirm.hidden;
        if (sdkConfirm.hidden) sdkCheck.checked = false;
        const callbackAnswer=form.querySelector('[data-incoming-callback-answer]');
        if(callbackAnswer){callbackAnswer.hidden=hasAnswer;callbackAnswer.textContent='La centralita registró esta devolución sin conversación confirmada. Puedes conservar la institución identificada; para crear el expediente desde una llamada se requiere una conversación confirmada.';}
        renderEvidence();
        clearError();
    };
    decision.addEventListener('change', () => {
        resetConfirmed();
        renderDecision();
        void loadMunicipalities();
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
        if (!button || isSubmitting || modalEl.classList.contains('show')) return;
        interrupted=false;resultTrigger=button;const cycle=++modalCycle;formDirty=false;draftStatus.hidden=true;
        form.reset();
        ++municipalitySequence;
        if(municipality){municipality.replaceChildren(new Option('Selecciona primero un estado',''));municipality.disabled=true;}
        clearError();
        resetConfirmed();
        const id = String(button.dataset.callId || '');
        isSdkCall = button.dataset.source === 'SDK_WEBRTC';
        form.querySelector('[data-incoming-pbx]').value = isSdkCall ? '' : id;
        form.querySelector('[data-incoming-sdk-id]').value = isSdkCall ? id : '';
        const sdkAlias=String(button.dataset.sdkReference||'');
        form.querySelector('[data-incoming-sdk-reference]').value=isSdkCall?'':sdkAlias;
        callRefs=[...new Set([id,sdkAlias,button.dataset.pbxCallId].filter(Boolean))];
        sdkNotice.hidden = !isSdkCall;
        form.querySelector('[data-incoming-number]').textContent =
            String(button.dataset.phone || 'Número privado');
        let durationSeconds = 0;
        const listedDuration = String(button.dataset.duration || '00:00');
        const parts = /^(\d{1,3}):(\d{2})$/.exec(listedDuration);
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
            if (option.value !== 'sin_identificar') option.disabled = !hasAnswer && !callbackMode;
        }
        modalEl.querySelector('[data-incoming-modal-description]').textContent = hasAnswer
            ? 'Vincula la conversación a un expediente y registra su resultado.'
            : callbackMode?'Devolución sin conversación confirmada. Registra el resultado del intento.':'Llamada sin respuesta: clasifícala sin crear un expediente.';
        renderDecision();
        // Si Recepción identificó el expediente, precargarlo por ID exacto
        // de esta sesión. El servidor volverá a validar la pertenencia al
        // Analista al guardar; nunca se asume que teléfono = identidad.
        if (id) {
            try {
                const contextId=sdkAlias||id;
                const key = 'impe:crm:transfer-context:' + userId + ':' + contextId;
                const context = JSON.parse(sessionStorage.getItem(key) || 'null');
                if (context && context.call_id === contextId &&
                    Number(context.seguimiento_id) > 0 &&
                    Date.now() - Number(context.saved_at || 0) < 900000) {
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
                        'Expediente identificado por Recepción. Confirma los datos y registra el resultado.';
                }
            } catch (_) {}
        }
        if(callbackMode){
            form.elements.intento_id.value=button.dataset.attemptId;
            if(button.dataset.followupId){decision.value='existente';followup.add(new Option(button.dataset.institution||'Expediente propio',button.dataset.followupId));followup.value=button.dataset.followupId;}
            else if(button.dataset.institution){decision.value='identificada';form.elements.institucion_nombre.value=button.dataset.institution;}
            if(button.dataset.suggestedResult)resultInput.value=button.dataset.suggestedResult;
            form.elements.observacion.maxLength=500;renderDecision();
        }
        const restore=async()=>{
            const values=await drafts?.read(userId,callRefs);
            if(!values||cycle!==modalCycle||formDirty)return;
            const normalized=drafts?.resultValues?.(values)||values;
            for(const [key,value] of Object.entries(normalized)) {
                const el=form.elements.namedItem(key);
                if(!el||['csrf_token','pbx_call_id','sdk_session_id','sdk_reference','confirmacion_manual_sdk'].includes(el.name))continue;
                if(el.type==='checkbox')el.checked=value==='1';
                else if(el.name==='seguimiento_id'&&value) {
                    // Recuperar el ID elegido conserva el borrador; el servidor revalida su cartera.
                    if(!Array.from(el.options).some(o=>o.value===value))el.add(new Option('Expediente seleccionado · #'+value,value));
                    el.value=value;
                } else el.value=String(value||'');
            }
            if(values.telefono && !form.elements.nuevo_telefono_contacto.value)form.elements.nuevo_telefono_contacto.value=values.telefono;
            if(values.correo && !form.elements.nuevo_correo_contacto.value)form.elements.nuevo_correo_contacto.value=values.correo;
            renderDecision();void loadMunicipalities(values.municipio_id||'');draftStatus.hidden=false;draftStatus.textContent='Borrador recuperado. Revisa los datos antes de guardar.';
        };
        void restore();
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

    // Audio PBX confirmado de entrantes; no vincular por aproximación la sesión SDK.
    page.addEventListener('click',async event=>{
        const button=event.target.closest('[data-confirm-verification]');if(!button||button.disabled)return;
        button.disabled=true;
        try {
            const body=new FormData();body.set('csrf_token',csrf());body.set('pbx_call_id',button.dataset.confirmVerification);
            body.set('sdk_reference',button.dataset.sdkReference);body.set('confirmar_verificacion','1');
            const res=await fetch(saveUrl,{method:'POST',credentials:'same-origin',headers:{Accept:'application/json'},body});
            const data=await res.json();if(!res.ok||!data.ok)throw new Error(data.mensaje||'No se pudo confirmar la verificación.');
            if(!data.resultado?.verificacion_confirmada)throw new Error('La verificación ya fue confirmada o todavía no tiene evidencia pendiente. Actualiza el historial.');
            sessionStorage.setItem('impe:call-result-flash','Verificación confirmada. Se contabiliza como máximo una institución por día.');
            window.location.reload();
        }catch(error){showError(error.message);button.disabled=false;}
    });
    page.addEventListener('click', async event => {
        const button = event.target.closest('[data-incoming-pbx-toggle]');
        if (!button || !page.contains(button)) return;
        event.preventDefault();
        const panel = document.getElementById(button.getAttribute('aria-controls'));
        if (!panel) return;
        if (!panel.hidden) {
            panel.querySelectorAll('audio').forEach(a => a.pause());
            panel.hidden = true;
            button.setAttribute('aria-expanded', 'false');
            return;
        }
        const error = panel.querySelector('[data-incoming-pbx-error]');
        const host = panel.querySelector('[data-incoming-pbx-audios]');
        const heading=panel.querySelector('.linkage-call-inline-player-heading span');
        heading.textContent='Verificando grabación…';error.hidden=true;host.replaceChildren();panel.hidden=false;
        const pbx = button.dataset.pbxId || '';
        const salidaInstitucion=button.dataset.pbxSalidaInstitucion==='1';
        const url = new URL(salidaInstitucion
            ? 'prueba_telefonia/api/grabacion_transferida_institucion.php'
            : 'prueba_telefonia/api/grabacion_transferida_pbx.php', document.baseURI);
        url.searchParams.set('pbx_call_id', pbx);
        if(salidaInstitucion)url.searchParams.set('seguimiento_id',button.dataset.seguimientoId||'0');
        url.searchParams.set('verificar', '1');
        button.disabled = true;
        try {
            const response = await fetch(url.toString(), {credentials:'same-origin', cache:'no-store'});
            const info = await response.json().catch(()=>({}));
            const message=window.IMPE_TELEPHONY_SEGMENTS.message(info,response.status);
            if (!response.ok) throw new Error(message);
            if (!info.ok || !info.disponible || !(info.segmentos > 0)) {
                throw new Error(message);
            }
            if (!window.IMPE_TELEPHONY_SEGMENTS?.create(
                host, url.toString(), info.segmentos,info.segmentos_info||[])) {
                throw new Error('No fue posible preparar el reproductor.');
            }
            error.hidden = true;
            heading.textContent=message;
            panel.hidden = false;
            button.setAttribute('aria-expanded', 'true');
        } catch (e) {
            heading.textContent='Consulta de grabación';
            error.textContent = e.message || 'Grabación no disponible.';
            error.hidden = false;
            panel.hidden = false;
            button.setAttribute('aria-expanded','true');
        } finally {
            button.disabled = false;
        }
    });

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
        if(resultInput.value==='CONTACTO_REFERIDO' && !Array.from(referred.querySelectorAll('input')).some(e=>e.value.trim())) {showError('Captura algún dato de la persona o área referida.');return;}
        clearTimeout(draftTimer);
        const requestBody=new FormData(form);
        if(callbackMode)requestBody.set('accion','RESULTADO');
        const disabledState=Array.from(form.elements).map(e=>[e,e.disabled]);
        isSubmitting = true;
        disabledState.forEach(([e])=>e.disabled=true);
        modalEl.querySelectorAll('[data-bs-dismiss="modal"]').forEach(e=>e.disabled=true);
        submit.disabled = true;
        const original = submit.innerHTML;
        submit.textContent = 'Guardando…';
        try {
            const res = await fetch(saveUrl, {
                method: 'POST', credentials: 'same-origin',
                headers: {'Accept': 'application/json', 'X-Requested-With': 'fetch'},
                body: requestBody
            });
            const data = await res.json();
            if (!res.ok || !data.ok) {
                throw new Error(data.mensaje || 'No fue posible registrar la llamada.');
            }
            closingAfterSave=true;
            ++modalCycle;
            for(const id of callRefs) {
                try {
                    for(const prefix of ['call-draft','call-summary','transfer-context']) {
                        sessionStorage.removeItem('impe:crm:'+prefix+':'+userId+':'+id);
                    }
                }catch(_){}
                if(!callbackMode&&window.parent!==window)window.parent.postMessage({type:'IMPE_ANALYST_RESULT_SAVED',callId:id},window.location.origin);
            }
            void drafts?.remove(userId,callRefs,requestBody.get('csrf_token')).catch(()=>{});
            if(callbackMode){
                modalEl.dispatchEvent(new CustomEvent('impe:callback-result-saved',{detail:data.intento}));
                modal.hide();return;
            }
            const result=data.resultado||{};
            const message=result.verificacion_confirmada ? 'Atención guardada con verificación efectiva. Máximo una institución por día.' :
                result.verificacion_pendiente ? 'Atención guardada. La verificación queda pendiente de confirmar la llamada.' : 'Atención guardada.';
            try {sessionStorage.setItem('impe:call-result-flash',message);}catch(_){}
            const linked=Number(result.seguimiento_id||0);
            const isUnifiedIncoming=new URLSearchParams(location.search).get('action')==='misLlamadas'
                && new URLSearchParams(location.search).get('vista')==='recibidas';
            const target=linked>0?'index.php?controller=seguimientoVinculacion&action=detalle&id='+linked:
                (isUnifiedIncoming?'index.php?controller=telefonia&action=misLlamadas&vista=recibidas':
                 'index.php?controller=seguimientoVinculacion&action=llamadasEntrantes');
            modalEl.addEventListener('hidden.bs.modal',()=>window.location.assign(new URL(target,window.location.href).toString()),{once:true});
            modal.hide();
        } catch (error) {
            showError(error.message || 'No fue posible guardar. Intenta nuevamente.');
        } finally {
            if (!closingAfterSave) {
                submit.innerHTML = original;
                isSubmitting = false;
                disabledState.forEach(([e,disabled])=>e.disabled=disabled);
                modalEl.querySelectorAll('[data-bs-dismiss="modal"]').forEach(e=>e.disabled=false);
            }
        }
    });

    if(callbackMode){window.addEventListener('impe:callback-result-open',e=>openRegistration(e.detail));window.addEventListener('impe:callback-result-interrupt',()=>{interrupted=true;modal.hide();});}
    renderDecision();
    // Al consultar una fila desde Historial, enfocar su tarjeta en lugar de
    // abrir el formulario de resultado. Un PBX puede no tener registro
    // equivalente en esta bandeja: informarlo sin inventar grabaciones.
    const detailId = String(page.dataset.focusCall || '').trim();
    if (detailId) {
        const row = Array.from(page.querySelectorAll('[data-incoming-row]'))
            .find(el => el.dataset.incomingRow === detailId);
        if (row) {
            row.classList.add('impe-incoming-targeted');
            row.setAttribute('tabindex', '-1');
            window.setTimeout(() => {
                row.scrollIntoView({behavior:'smooth',block:'center'});
                row.focus({preventScroll:true});
                // Sólo desplegar la grabación si el servidor publicó un
                // botón disponible. La reproducción nunca arranca sola.
                const audioToggle = row.querySelector(
                    '[data-incoming-pbx-toggle], [data-incoming-audio-toggle]'
                );
                if (audioToggle && audioToggle.getAttribute('aria-expanded') !== 'true') {
                    audioToggle.click();
                }
            }, 160);
        } else {
            const info = document.createElement('div');
            info.className = 'linkage-incoming-feedback';
            info.setAttribute('role','status');
            info.textContent = 'Este tramo PBX está en tu historial, pero no se encontró su ficha individual en Recibidas. No se generó ninguna grabación adicional ni se modificó su resultado.';
            page.querySelector('.linkage-incoming-heading')?.after(info);
        }
    }
    const focusId = String(page.dataset.autoCall || '').trim();
    if (focusId) {
        // Tras una transferencia el enlace puede usar el ID WebRTC o PBX.
        // Ambas referencias identifican la misma tarjeta consolidada.
        const found = Array.from(page.querySelectorAll('[data-call-id]'))
            .find(el => el.dataset.callId === focusId ||
                el.dataset.pbxCallId === focusId || el.dataset.sdkReference===focusId);
        if (found) {
            const address=new URL(window.location.href);
            if(address.searchParams.get('impe_retry')==='1') {
                address.searchParams.delete('impe_retry');
                window.history.replaceState(null,'',address.toString());
            }
            window.setTimeout(() => openRegistration(found), 100);
        } else {
            // Puede llegar el salto desde el teléfono antes de publicarse
            // el registro PBX/WebRTC. Reintentar una sola vez, sin crear nada.
            const retry=new URL(window.location.href);
            if (retry.searchParams.get('impe_retry')!=='1') {
                retry.searchParams.set('impe_retry','1');
                window.setTimeout(() => window.location.replace(retry.toString()), 1500);
            } else {
                const aviso = document.createElement('p');
                aviso.className = 'alert alert-warning mt-3';
                aviso.setAttribute('role', 'status');
                aviso.textContent = 'La llamada se recibió, pero su registro aún no está disponible. Actualiza Llamadas recibidas para intentar abrir el resultado. No repitas la llamada.';
                page.appendChild(aviso);
            }
        }
    }
})();
