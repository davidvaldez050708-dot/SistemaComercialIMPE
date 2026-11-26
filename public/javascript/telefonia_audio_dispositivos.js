/* Piloto de selección de entrada. No crea teléfonos, PeerConnections ni sesiones SIP. */
(function () {
    'use strict';
    const aliases = id => !id || id === 'default' || id === 'communications';
    const explicitIds = value => {
        if (typeof value === 'string') return [value];
        if (!value || typeof value !== 'object') return [];
        return [].concat(value.exact || value.ideal || []).filter(id => typeof id === 'string');
    };
    const create = function (w, config, readState, readSession, ownsTransport) {
        if (config?.enabled !== true || !(Number(config.userId) > 0) || !/^[0-9]{3,6}$/.test(String(config.extension || ''))) return null;
        const media = w.navigator.mediaDevices;
        if (!media?.getUserMedia || !media.enumerateDevices) return null;
        const key = 'impe:audio-input:v1:' + config.userId + ':' + config.extension;
        const original = media.getUserMedia;
        let preferred = '', devices = [], permission = 'unknown', permissionHandle;
        let actual = null, lastActual = null, notice = '', disposed = false, refreshing = null;
        let deferredToken = null, temporaryBusy = false, storageOK = true, root, timer;
        let ui, select, status, used, wanted, testButton, lastView = '';
        let toggle, anchor, anchorDoc, owner, crmFrame, reviewRequired = true, presentationQueued = false, wasActive = false;
        const bindings = new Map();
        const liveTracks = () => {
            try {
                const session = readSession();
                if (!session || session.isEnded?.()) return [];
                return (session.connection?.getSenders?.() || []).map(sender => sender.track)
                    .filter(track => track?.kind === 'audio' && track.readyState === 'live');
            } catch (_) { return []; }
        };
        const active = () => {
            try { return Boolean(readState()?.active || liveTracks().length || readSession()?.isEstablished?.()); }
            catch (_) { return true; }
        };
        const available = id => devices.some(device => device.deviceId === id);
        try {
            const saved = JSON.parse(w.localStorage.getItem(key) || 'null');
            if (saved?.version === 1 && typeof saved.deviceId === 'string') preferred = saved.deviceId;
        } catch (_) { storageOK = false; }

        const snapshot = () => ({
            devices: devices.map(d => ({deviceId:d.deviceId, label:d.label, groupId:d.groupId})),
            preferred, actual:actual && {...actual}, permission, notice, active:active(), storageOK,
            pending:Boolean(preferred && (!actual || actual.deviceId !== preferred)), temporaryBusy, reviewRequired
        });
        const render = () => {
            if (!ui || disposed) return;
            const model = snapshot(), serialized = JSON.stringify(model);
            if (serialized === lastView) return;
            lastView = serialized;
            // Mantener el foco y no reconstruir las opciones mientras se navega por el selector.
            const selected = select.value;
            const signature = JSON.stringify(devices.map(d => [d.deviceId,d.label]));
            if (select.dataset.devices !== signature || select.dataset.preferred !== preferred) {
                select.replaceChildren();
                const option = (id, label) => {const o = ui.createElement('option');o.value=id;o.textContent=label;select.appendChild(o);};
                option('', 'Respetar navegador y SDK');
                devices.filter(d => !aliases(d.deviceId)).forEach((d, i) => option(d.deviceId, d.label || 'Micrófono ' + (i+1)));
                if (preferred && !available(preferred)) option(preferred, 'Micrófono preferido desconectado');
                select.value = preferred || selected || '';
                if (!preferred) select.value = '';
                select.dataset.devices=signature;select.dataset.preferred=preferred;
            }
            wanted.textContent = preferred
                ? (devices.find(d => d.deviceId === preferred)?.label || 'Preferencia guardada; dispositivo no disponible')
                : 'Navegador y SDK';
            used.textContent = actual
                ? (actual.label || 'Micrófono de la sesión') + (actual.muted ? ' · silenciado' : '')
                : 'Se verificará cuando WebRTC envíe audio.';
            const mismatch = actual && preferred && actual.deviceId !== preferred;
            const ambiguous = !preferred && !actual && devices.filter(d => !aliases(d.deviceId)).length > 1;
            status.textContent = permission === 'denied'
                ? 'Permiso de micrófono bloqueado. Autorízalo en este sitio para poder llamar.'
                : notice || (preferred && !available(preferred)
                    ? 'El preferido no está disponible. El teléfono conserva la captura habitual; puedes elegir otro.'
                    : mismatch ? 'El audio de la llamada usa otro micrófono. La preferencia queda pendiente para una nueva captura.'
                    : preferred && !actual ? 'Preferencia guardada para nuevas capturas. Aún no se ha verificado en WebRTC.'
                    : ambiguous ? 'Hay varios micrófonos. Elige el que deseas usar; conservamos la selección del navegador.'
                    : 'El teléfono conserva la selección actual. No se modifican llamadas en curso.');
            if (mismatch && notice && permission !== 'denied') status.textContent += ' WebRTC está usando un micrófono distinto del preferido.';
            if (!storageOK) status.textContent += ' El navegador no permite guardar la preferencia entre sesiones.';
            testButton.disabled = model.active || temporaryBusy;
            testButton.textContent = temporaryBusy ? 'Comprobando…' : 'Autorizar y detectar';
            updateReview();
        };
        const observe = () => {
            if (disposed) return;
            const tracks = liveTracks();
            actual = null;
            if (tracks.length === 1) {
                const t = tracks[0];
                try {
                    const settings = t.getSettings?.() || {};
                    const constraints = t.getConstraints?.() || {};
                    actual = {deviceId:settings.deviceId || '', label:t.label || '', muted:!t.enabled,
                        requestedDeviceId:explicitIds(constraints.deviceId)[0] || ''};
                    // Sólo getSettings identifica el dispositivo real; constraints no es prueba.
                    if (actual.deviceId) lastActual = {...actual};
                    if (preferred && actual.deviceId && actual.deviceId!==preferred) reviewRequired=true;
                } catch (_) { actual = null; }
            }
            const busy=active();
            if (!busy) deferredToken = null;
            if (busy && !wasActive) closePanel(); // Dejar libres Contestar/Colgar al iniciar o recibir.
            wasActive=busy;
            syncPresentation();
            render();
        };
        const refresh = () => {
            if (refreshing) return refreshing;
            refreshing = Promise.resolve().then(() => media.enumerateDevices()).then(list => {
                if (disposed) return;
                devices = list.filter(d => d.kind === 'audioinput').map(d => ({deviceId:d.deviceId,label:d.label,groupId:d.groupId}));
                observe();
            }).catch(() => {notice='No se pudo leer la lista de dispositivos. El teléfono conserva su captura habitual.';render();})
                .finally(() => {refreshing=null;});
            return refreshing;
        };
        const choose = id => {
            if (id && (!available(id) || aliases(id))) return false;
            preferred = id;
            reviewRequired = true;
            deferredToken = active() ? String(readState()?.callToken || 'active') : null;
            notice = active() ? 'Preferencia guardada para próximas llamadas. La llamada actual continúa sin cambios.' : '';
            try {
                if (id) w.localStorage.setItem(key, JSON.stringify({version:1,deviceId:id}));
                else w.localStorage.removeItem(key);
                storageOK = true;
            } catch (_) {storageOK=false;}
            render(); return true;
        };
        const plan = constraints => {
            if (!constraints?.audio || !ownsTransport() || permission === 'denied') return constraints;
            const state = readState() || {};
            if (liveTracks().length || readSession()?.isEstablished?.() ||
                (state.active && !['dialing','ringing','incoming-ringing','answering'].includes(state.phase)) ||
                (deferredToken !== null && state.active && deferredToken === String(state.callToken || 'active'))) return constraints;
            const audio = constraints.audio;
            const sdkIds = typeof audio === 'object'
                ? [audio, ...(Array.isArray(audio.advanced) ? audio.advanced : [])]
                    .flatMap(c => explicitIds(c?.deviceId)).filter(id => !aliases(id)) : [];
            // También conservar IDs que aún no podemos enumerar (permiso/visibilidad).
            if (sdkIds.length || (typeof audio === 'object' && (audio.mandatory || audio.optional))) return constraints;
            let id = preferred && available(preferred) ? preferred : '';
            if (preferred && !id) return constraints;
            if (!id && lastActual && available(lastActual.deviceId)) return constraints;
            if (!id) {
                const physical = devices.filter(d => !aliases(d.deviceId));
                const external = physical.filter(d => /usb|headset|diadema|auricular/i.test(d.label));
                const defaultInput = devices.find(d => d.deviceId === 'default');
                // Nunca deducir el dispositivo de Windows. No imponer externo si existe
                // un predeterminado identificable o varias entradas físicas posibles.
                if (physical.length === 1 && external.length === 1 && !defaultInput?.label && !defaultInput?.groupId) id=external[0].deviceId;
            }
            if (!id) return constraints;
            return {...constraints, audio:{...(typeof audio === 'object' ? audio : {}), deviceId:{exact:id}}};
        };
        const wrapped = async function (constraints) {
            let chosen = constraints;
            try {if (!disposed) chosen=plan(constraints);} catch (_) {chosen=constraints;}
            try {return await original.call(media, chosen);}
            catch (error) {
                // Un permiso denegado no provoca un segundo diálogo. Nunca reintentar
                // llamadas originales: sólo la restricción añadida por este piloto.
                if (chosen === constraints || !['OverconstrainedError','NotFoundError','ConstraintNotSatisfiedError','DevicesNotFoundError'].includes(error?.name)) throw error;
                notice='El preferido no pudo abrirse; se usará la captura habitual. Vuelve a comprobar los dispositivos.';
                render();
                return original.call(media, constraints);
            }
        };
        const authorize = async () => {
            if (active() || temporaryBusy || !ownsTransport() || permission === 'denied') return false;
            temporaryBusy=true;render();
            let stream;
            try {
                // Acción voluntaria, sin la selección piloto: sólo descubrir etiquetas.
                stream=await original.call(media,{audio:true});
                stream.getTracks().forEach(t => t.stop());stream=null;
                permission='granted';notice='Dispositivos detectados. La selección se comprobará en la próxima llamada.';
                await refresh();return true;
            } catch (error) {
                if (error?.name === 'NotAllowedError' || error?.name === 'SecurityError') permission='denied';
                notice='No se pudo autorizar o detectar el micrófono. No se modificó el teléfono.';return false;
            } finally {stream?.getTracks().forEach(t=>t.stop());temporaryBusy=false;render();}
        };
        const devicechange = () => {
            reviewRequired = true;
            notice=active() ? 'Cambió un dispositivo de audio. No modificaremos la llamada en curso; revisa el micrófono para las próximas llamadas.' : '';
            void refresh();
        };
        const closePanel = (restoreFocus = false) => {
            if (!root) return;
            root.hidden = true;toggle?.setAttribute('aria-expanded','false');
            if (restoreFocus && toggle?.isConnected) toggle.focus();
        };
        const positionPanel = () => {
            if (!root || root.hidden || !anchor?.isConnected) return;
            try {
                const box=(toggle || anchor).getBoundingClientRect(), view=ui.defaultView, gap=10;
                const frame=anchorDoc===ui ? null : crmFrame?.getBoundingClientRect();
                const scale=frame ? frame.width / Math.max(1,anchorDoc.documentElement.clientWidth) : 1;
                const right=(frame?.left || 0)+box.right*scale;
                const bottom=(frame?.top || 0)+box.bottom*scale;
                const width=Math.min(360,Math.max(1,view.innerWidth-gap*2));
                root.style.width=width+'px';
                root.style.maxHeight=Math.max(80,view.innerHeight-gap*2)+'px';
                const height=root.getBoundingClientRect().height;
                root.style.left=Math.max(gap,Math.min(view.innerWidth-width-gap,right-width))+'px';
                root.style.top=Math.max(gap,Math.min(view.innerHeight-height-gap,bottom+8))+'px';
            } catch (_) {closePanel();}
        };
        const openPanel = () => {
            if (!root || !toggle) return;
            root.hidden=false;toggle.setAttribute('aria-expanded','true');positionPanel();
        };
        const updateReview = () => {
            const required=reviewRequired || permission==='denied' || Boolean(preferred && !available(preferred));
            toggle?.classList.toggle('needs-review',required);
            toggle?.setAttribute('title',required ? 'Dispositivos de audio · revisa el micrófono antes de llamar' : 'Dispositivos de audio');
            for (const [doc] of bindings) for (const advice of doc.querySelectorAll('[data-audio-before-call]')) {
                advice.hidden=!required || active();
            }
            const reviewed=root?.querySelector('[data-audio-reviewed]');
            if (reviewed) reviewed.disabled=active() || permission==='denied' || Boolean(preferred && !available(preferred));
        };
        const markReviewed = () => {
            if (active() || permission==='denied' || (preferred && !available(preferred))) return;
            reviewRequired=false;render();closePanel(true);
        };
        const queuePresentation = () => {
            if (presentationQueued || disposed) return;
            presentationQueued=true;
            void Promise.resolve().then(()=>{presentationQueued=false;if (!disposed) {syncPresentation();updateReview();}});
        };
        const bindDocument = doc => {
            if (bindings.has(doc)) return;
            const link=doc.createElement('link');link.rel='stylesheet';link.href=config.cssUrl;doc.head.appendChild(link);
            const click = event => {
                if (event.target.closest?.('[data-audio-review]')) {openPanel();return;}
                if (!root?.contains(event.target) && !toggle?.contains(event.target)) closePanel();
                queuePresentation();
            };
            const keydown = event => {if (event.key==='Escape' && root && !root.hidden) {closePanel(true);event.stopPropagation();}};
            const resize = () => positionPanel();
            doc.addEventListener('click',click,true);doc.addEventListener('keydown',keydown,true);
            doc.addEventListener('scroll',resize,true);doc.defaultView.addEventListener('resize',resize);
            const observer=w.MutationObserver ? new w.MutationObserver(queuePresentation) : null;
            observer?.observe(doc.body,{childList:true,subtree:true});
            bindings.set(doc,{link,click,keydown,resize,observer});
        };
        const unbindDocument = doc => {
            const b=bindings.get(doc);if (!b) return;
            b.observer?.disconnect();b.link.remove();
            doc.removeEventListener('click',b.click,true);doc.removeEventListener('keydown',b.keydown,true);
            doc.removeEventListener('scroll',b.resize,true);doc.defaultView?.removeEventListener('resize',b.resize);
            doc.querySelectorAll('[data-audio-before-call]').forEach(node=>node.remove());
            bindings.delete(doc);
        };
        const frameLoaded = () => {closePanel();queuePresentation();};
        const syncPresentation = () => {
            if (disposed) return;
            try {
                if (!owner) {
                    if (w.parent!==w && w.parent.IMPE_TELEPHONY_SHELL?.ownsPhoneHost?.(w)) owner=w.parent;
                    else if (new URLSearchParams(w.location.search).get('display')==='tab') owner=w;
                    else return;
                    ui=owner.document;
                }
                if (!ui.body) return;
                bindDocument(ui);
                const nextFrame=ui.getElementById('impeCrmFrame');
                if (crmFrame!==nextFrame) {
                    crmFrame?.removeEventListener('load',frameLoaded);crmFrame=nextFrame;
                    crmFrame?.addEventListener('load',frameLoaded);
                }
                const doc=crmFrame ? crmFrame.contentDocument : ui;
                if (!doc?.body) return;
                if (anchorDoc!==doc) {
                    closePanel();toggle?.remove();toggle=anchor=null;
                    if (anchorDoc && anchorDoc!==ui) unbindDocument(anchorDoc);
                    anchorDoc=doc;bindDocument(doc);
                }
                // Anclar al auricular real del encabezado. No editar topbar.php ni
                // dejar un botón fijo encima del perfil o los controles de llamada.
                anchor=doc.querySelector('.topbar-actions [data-telephony-reception-toggle]');
                const actions=anchor?.parentElement || doc.querySelector('.topbar-actions') ||
                    (owner===w ? doc.querySelector('.telephony-host-tab-info') : null);
                if (!actions) {closePanel();return;}
                if (!toggle?.isConnected || toggle.parentElement!==actions) {
                    toggle?.remove();toggle=doc.createElement('button');toggle.type='button';
                    toggle.className='topbar-reminder-button impe-audio-devices-toggle';
                    toggle.setAttribute('data-audio-devices-toggle','');toggle.setAttribute('aria-label','Dispositivos de audio');
                    toggle.setAttribute('aria-haspopup','dialog');toggle.setAttribute('aria-expanded','false');
                    toggle.innerHTML='<i class="bi bi-mic" aria-hidden="true"></i>';
                    toggle.addEventListener('click',()=>{if (root?.hidden) openPanel();else closePanel();});
                    if (anchor) anchor.after(toggle);else actions.prepend(toggle);
                }
                if (!anchor) anchor=toggle;
                if (!root) mountPanel();
                for (const bound of [ui,doc]) for (const form of bound.querySelectorAll('[data-dialer-form], [data-telephony-dialer-form], [data-phone-active-actions]')) {
                    if (form.querySelector('[data-audio-before-call]')) continue;
                    const advice=bound.createElement('div');advice.className='impe-audio-before-call';advice.setAttribute('data-audio-before-call','');
                    advice.setAttribute('role','status');
                    advice.innerHTML='<i class="bi bi-mic" aria-hidden="true"></i><span>Antes de llamar, revisa que estés usando el micrófono correcto.</span><button type="button" data-audio-review>Revisar micrófono</button>';
                    const button=form.querySelector('[data-dialer-call], [data-telephony-dial-call], [data-phone-start]');
                    if (button) button.before(advice);else form.prepend(advice);
                }
                toggle.setAttribute('aria-expanded',String(Boolean(root && !root.hidden)));
                positionPanel();
                updateReview();
            } catch (_) { /* Navegación o documento aún no disponible: reintentar sin tocar el teléfono. */ }
        };
        const mountPanel = () => {
            try {
                if (ui.querySelector('[data-impe-audio-devices]')) return;
                root=ui.createElement('section');root.className='impe-audio-devices';root.hidden=true;
                root.setAttribute('data-impe-audio-devices','');root.setAttribute('role','dialog');root.setAttribute('aria-label','Dispositivos de audio');
                root.innerHTML='<div class="impe-audio-devices-heading"><strong>Dispositivos de audio</strong><button type="button" data-audio-close aria-label="Cerrar dispositivos de audio"><i class="bi bi-x-lg" aria-hidden="true"></i></button></div>'+
                    '<div class="impe-audio-devices-body"><label>Micrófono preferido<select aria-label="Micrófono preferido"></select></label>'+
                    '<dl><dt>Preferido</dt><dd data-audio-preferred></dd><dt>Enviado por WebRTC</dt><dd data-audio-used></dd></dl>'+
                    '<p role="status" aria-live="polite"></p><div class="impe-audio-devices-actions"><button type="button" data-audio-refresh>Actualizar lista</button>'+
                    '<button type="button" data-audio-test>Autorizar y detectar</button><button type="button" data-audio-reviewed>Ya revisé mi micrófono</button></div>'+
                    '<small>La revisión es tu confirmación de los dispositivos. El micrófono enviado se verifica al conversar; la llamada en curso no cambia.</small></div>';
                select=root.querySelector('select');status=root.querySelector('[role="status"]');
                used=root.querySelector('[data-audio-used]');wanted=root.querySelector('[data-audio-preferred]');testButton=root.querySelector('[data-audio-test]');
                select.addEventListener('change',()=>choose(select.value));
                root.querySelector('[data-audio-refresh]').addEventListener('click',()=>void refresh());
                testButton.addEventListener('click',()=>void authorize());
                root.querySelector('[data-audio-reviewed]').addEventListener('click',markReviewed);
                root.querySelector('[data-audio-close]').addEventListener('click',()=>closePanel(true));
                ui.body.appendChild(root);lastView='';render();
            } catch (_) { /* La interfaz opcional no impide llamar. */ }
        };
        const dispose = () => {
            disposed=true;w.clearInterval(timer);
            media.removeEventListener?.('devicechange',devicechange);
            permissionHandle?.removeEventListener?.('change', permissionChanged);
            if (media.getUserMedia === wrapped) media.getUserMedia=original;
            for (const doc of [...bindings.keys()]) unbindDocument(doc);
            crmFrame?.removeEventListener('load',frameLoaded);toggle?.remove();root?.remove();
            w.removeEventListener('pagehide',pagehide);
        };
        const permissionChanged = () => {if (permission!==permissionHandle.state) reviewRequired=true;permission=permissionHandle.state;observe();};
        const pagehide = event => {if (!event.persisted) dispose();};
        try {media.getUserMedia=wrapped;} catch (_) {return null;}
        media.addEventListener?.('devicechange',devicechange);
        void Promise.resolve().then(()=>w.navigator.permissions?.query({name:'microphone'})).then(handle=>{
            if (disposed || !handle) return;
            permissionHandle=handle;permissionChanged();handle.addEventListener('change',permissionChanged);
        }).catch(()=>{});
        syncPresentation();void refresh();
        timer=w.setInterval(observe,750);
        w.addEventListener('pagehide',pagehide);
        return Object.freeze({snapshot,refresh,choose,authorize,observe,dispose});
    };
    window.IMPE_AUDIO_DEVICES_FACTORY=Object.freeze({create});
})();
