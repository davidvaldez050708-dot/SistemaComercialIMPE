/* Directorio y ficha compacta de llamada: no controla ni reinicia Zadarma.
 * El HOST revalida la autorización antes de emitir cualquier tono DTMF.
 */
(function () {
    'use strict';
    const cfg = window.IMPE_PHONE_SHELL_CONFIG || {};
    const api = window.IMPE_TELEPHONY_SHELL;
    if (!api || !cfg.userId) return;
    const $ = selector => document.querySelector(selector);
    const toggle = $('[data-shell-transfer-toggle]');
    const panel = $('[data-shell-transfer-panel]');
    const captureToggle = $('[data-shell-capture-toggle]');
    const capturePanel = $('[data-shell-capture-panel]');
    const salesCapturePanel = $('[data-sales-prospect-dialog]');
    const tipo = $('[data-routing-type]');
    const estado = $('[data-routing-state]');
    const buscar = $('[data-routing-search]');
    const destinos = $('[data-routing-destination]');
    const coincidencias = $('[data-routing-matches]');
    const lista = $('[data-routing-matches-list]');
    const feedback = $('[data-routing-feedback]');
    const identityBox = $('[data-routing-identification]');
    const lookupToggle = $('[data-routing-search-toggle]');
    const lookupWrap = $('[data-routing-search-wrap]');
    const lookupLabel = document.querySelector('label[for="impeRoutingSearch"]');
    const phonePanel = $('[data-incoming-panel]');
    // Sacar las fichas del panel telefónico para no modificar su tamaño,
    // posición guardada, arrastre ni los controles de colgar/silenciar.
    [panel, capturePanel, salesCapturePanel].filter(Boolean).forEach(pane => {
        pane.classList.add('impe-routing-sidecar');
        document.body.appendChild(pane);
    });
    // Ajustar la ficha al viewport VISIBLE: teclado móvil, zoom y rotación
    // pueden reducir visualViewport sin modificar innerWidth/innerHeight.
    // No mover ni redimensionar el teléfono/iframe de Zadarma.
    const positionSidecar = pane => {
        if (!pane || pane.hidden) return;
        const visual = window.visualViewport;
        const left = Math.max(0, Number(visual?.offsetLeft) || 0);
        const top = Math.max(0, Number(visual?.offsetTop) || 0);
        const visibleWidth = Math.max(0, Math.min(window.innerWidth, visual?.width || window.innerWidth));
        const visibleHeight = Math.max(0, Math.min(window.innerHeight, visual?.height || window.innerHeight));
        const padding = 8;
        const width = Math.max(0, Math.min(420, visibleWidth - padding * 2));
        const height = Math.max(0, Math.min(530, visibleHeight - padding * 2));
        const rect = phonePanel?.getBoundingClientRect();
        const margin = 10;
        let x = left + visibleWidth - width - padding;
        let y = top + padding;

        // En escritorio mantener el panel al lado del teléfono. En pantallas
        // estrechas, mostrarlo dentro del área visible, con scroll independiente.
        if (rect && visibleWidth > 760) {
            const rightSpace = left + visibleWidth - rect.right;
            const leftSpace = rect.left - left;
            if (rightSpace >= width + margin) x = rect.right + margin;
            else if (leftSpace >= width + margin) x = rect.left - width - margin;
            else x = left + (visibleWidth - width) / 2;
            y = Math.max(top + padding, rect.top);
        }
        x = Math.max(left + padding, Math.min(x, left + visibleWidth - width - padding));
        y = Math.max(top + padding, Math.min(y, top + visibleHeight - height - padding));
        pane.style.width = Math.round(width) + 'px';
        pane.style.maxHeight = Math.round(height) + 'px';
        pane.style.left = Math.round(x) + 'px';
        pane.style.top = Math.round(y) + 'px';
        pane.style.right = 'auto';
        pane.style.bottom = 'auto';
    };
    const repositionSidecars = () => {
        positionSidecar(panel);
        positionSidecar(capturePanel);
        positionSidecar(salesCapturePanel);
    };
    document.addEventListener('impe:call-capture-layout', event => {
        if (event.target === salesCapturePanel) positionSidecar(salesCapturePanel);
    });
    window.addEventListener('resize', repositionSidecars);
    window.addEventListener('pointerup', repositionSidecars);
    window.visualViewport?.addEventListener('resize', repositionSidecars);
    window.visualViewport?.addEventListener('scroll', repositionSidecars);
    const categoria = cfg.analystIncomingResults
        ? 'ANALISTA'
        : cfg.canCapture ? 'VENTAS' : 'RECEPCION';
    let targetEntries = [];
    let selected = null;
    let timer = 0;
    let searchSequence = 0;
    let confirmDirect = '';
    let recognized = [];
    let phoneSuggestions = [];
    let confirmedRecognition = false;
    let confirmedRecord = null;
    let phoneCheckedForCall = '';
    let rejectedRecognitionForCall='';
    let currentCallId = '';
    let transferRequestCallId = '';
    let transferDestinationExtension = '';
    let transferBusy = false;
    let transferSubmittedAt = 0;
    const listener = typeof BroadcastChannel === 'function'
        ? new BroadcastChannel('impe-telephony-' + Number(cfg.userId))
        : null;
    const onPhoneState = value => {
        if (!transferRequestCallId || !value ||
            String(value.userId || '') !== String(cfg.userId)) return;
        const current = call();
        const stillSame = current?.id === transferRequestCallId;
        if (!stillSame && value.active) return;
        if (!value.active && value.phase === 'incoming-finished') {
            if (value.status === 'transferred') {
                setFeedback('Zadarma confirmó la transferencia de la llamada.');
            }
            transferRequestCallId = '';
            transferDestinationExtension = '';
            transferSubmittedAt = 0;
            return;
        }
        const message = String(value.message || '');
        if (!stillSame || !message ||
            Number(value.updatedAt || 0) < transferSubmittedAt) return;
        // Los rechazos de validacion se producen ANTES del DTMF y no
        // incluyen necesariamente 'ext. 101'. Deben mostrarse al usuario.
        const rechazoDeValidacion = [
            'No fue posible confirmar la institución para transferir.',
            'No fue posible conservar la institución.',
            'No fue posible conservar la institución confirmada.',
            'La autorización de transferencia caducó',
            'La llamada cambió.',
            'El destino ya no está autorizado.',
            'No se autorizó la transferencia.',
            'No se pudo solicitar la transferencia.',
            'No existe una llamada activa autorizada para transferir.',
            'Selecciona el destino autorizado desde Transferir.'
        ].some(prefix => message.startsWith(prefix));
        const correspondeExtension = message.includes('ext. ' + transferDestinationExtension);
        if (rechazoDeValidacion ||
            (correspondeExtension && message.startsWith('No se pudo enviar la transferencia'))) {
            setFeedback(message, true);
            transferRequestCallId = '';
            transferDestinationExtension = '';
            transferSubmittedAt = 0;
        } else if (correspondeExtension &&
                   (message.startsWith('Solicitando transferencia directa') ||
                    message.startsWith('Consultando a la extensión'))) {
            // Tonos enviados no equivale a transferencia PBX confirmada.
            setFeedback(message);
        }
    };
    listener?.addEventListener('message', event => {
        if (event.data?.type === 'STATE') onPhoneState(event.data.state);
    });
    window.addEventListener('storage', event => {
        if (event.key !== 'impe:telephony:state:' + Number(cfg.userId) ||
            !event.newValue) return;
        try { onPhoneState(JSON.parse(event.newValue)); } catch (_) {}
    });
    const endpoint = (key, params) => {
        const url = new URL(String(cfg[key] || ''), window.location.href);
        Object.entries(params || {}).forEach(([name, val]) =>
            url.searchParams.set(name, String(val)));
        return url.toString();
    };
    const setFeedback = (text, isError) => {
        if (!feedback) return;
        feedback.textContent = text;
        feedback.classList.toggle('is-error', Boolean(isError));
        feedback.hidden=!isError && !document.querySelector('[data-availability-source]')?.hidden;
    };
    const call = () => api.getActiveCall?.() || null;
    const readJson = async response => {
        const data = await response.json();
        if (!response.ok || !data.ok) {
            throw new Error(data.mensaje || 'No se pudo consultar el sistema.');
        }
        return data;
    };
    const option = (value, text) => {
        const el = document.createElement('option');
        el.value = String(value);
        el.textContent = String(text);
        return el;
    };
    const closeSection = (pane, btn) => {
        if (!pane) return;
        pane.hidden = true;
        btn?.setAttribute('aria-expanded', 'false');
    };
    const openSection = (pane, btn, otherPane, otherBtn) => {
        if (!pane || !btn || !call()) return;
        const opening = pane.hidden;
        closeSection(otherPane, otherBtn);
        pane.hidden = !opening;
        btn.setAttribute('aria-expanded', String(opening));
        if (opening) positionSidecar(pane);
    };

    const keyFor = item =>
        String(item.usuario_id)+':'+String(item.extension)+':'+String(item.estado_id);
    const permitted = () => targetEntries;
    const renderRecipients = (preferred = '') => {
        if (!destinos) return;
        const previous = preferred || destinos.value;
        const visible = permitted();
        destinos.replaceChildren(option('', 'Seleccionar estado y responsable'));
        visible.forEach(item => {
            const label = [item.estado_nombre, item.nombre, 'Ext. '+item.extension].filter(Boolean).join(' · ');
            destinos.appendChild(option(keyFor(item), label));
        });
        const chosen = visible.find(item => keyFor(item) === previous) ||
            (visible.length === 1 ? visible[0] : null);
        destinos.value = chosen ? keyFor(chosen) : '';
        selected = chosen;
        if(estado)estado.value=chosen?String(chosen.estado_id):'';
    };
    const chooseInstitution = record => {
        const dest = targetEntries.find(item =>
            Number(item.usuario_id) === Number(record.responsable_id) &&
            Number(item.estado_id) === Number(record.estado_id || estado?.value) &&
            String(item.extension) === String(record.extension));
        if (!dest) {
            setFeedback('El responsable de ese expediente no está disponible para transferir.', true);
            return;
        }
        if (estado) estado.value = String(dest.estado_id);
        renderRecipients(keyFor(dest));
        if(buscar){buscar.value=record.nombre;buscar.required=false;}if(lookupWrap)lookupWrap.hidden=true;
        confirmedRecognition = true;
        confirmedRecord = {callId: currentCallId, seguimientoId: Number(record.seguimiento_id || 0),
            usuarioId: Number(dest.usuario_id), estadoId: Number(dest.estado_id)};
        confirmDirect = '';
        if (identityBox) {
            identityBox.replaceChildren();
            const strong = document.createElement('strong');
            strong.textContent = record.nombre + ' · ' + dest.estado_nombre;
            identityBox.appendChild(strong);
            const detail = document.createElement('small');
            detail.textContent = 'Institución confirmada · ' + dest.nombre +
                ' · Ext. ' + dest.extension;
            identityBox.appendChild(detail);
            const change = document.createElement('button');
            change.type = 'button';
            change.textContent = 'Cambiar institución';
            change.addEventListener('click', () => {
                clearInstitution();
                rejectedRecognitionForCall='';
                renderIdentification(phoneSuggestions, call()?.phone || '');
                if (lookupWrap) lookupWrap.hidden = false;
                if (lookupToggle) lookupToggle.setAttribute('aria-expanded', 'true');
                buscar?.focus({preventScroll:true});
                positionSidecar(panel);
            });
            identityBox.appendChild(change);
            identityBox.hidden = false;
        }
        setFeedback('Responsable listo. Confirma verbalmente la transferencia.');
    };
    const clearInstitution = () => {
        confirmedRecord = null;
        confirmedRecognition = false;
        confirmDirect = '';
        searchSequence++;
        window.clearTimeout(timer);
        if (buscar) { buscar.value = ''; buscar.required = true; }
        if (estado) estado.value = '';
        if (destinos) destinos.value = '';
        selected = null;
        renderMatches([]);
    };
    const renderIdentification = (records, phone) => {
        if (!identityBox) return;
        identityBox.replaceChildren();
        identityBox.hidden = false;
        recognized = Array.isArray(records) ? records : [];
        if(rejectedRecognitionForCall===currentCallId)recognized=[];
        confirmedRecognition = false;
        const heading = document.createElement('strong');
        if (recognized.length && categoria === 'RECEPCION') {
            if (lookupWrap) lookupWrap.hidden = true;
            if (lookupToggle) {
                lookupToggle.hidden = false;
                lookupToggle.setAttribute('aria-expanded', 'false');
            }
        }
        if (!recognized.length) {
            heading.textContent = 'Número sin expediente identificado';
            identityBox.appendChild(heading);
            const hint = document.createElement('small');
            hint.textContent = 'Institución no identificada por teléfono. Pregunta su nombre y estado.';
            identityBox.appendChild(hint);
            if (phoneSuggestions.length) {
                const back = document.createElement('button');
                back.type='button';back.textContent='Volver a las sugerencias';
                back.addEventListener('click',()=>{
                    clearInstitution();rejectedRecognitionForCall='';
                    renderIdentification(phoneSuggestions,call()?.phone||'');
                    positionSidecar(panel);
                });
                identityBox.appendChild(back);
            }
            // Si es una llamada institucional nueva, mostrar el campo
            // directamente sin exigir que Tania abra otra sección.
            if (lookupWrap) lookupWrap.hidden = false;
            if(buscar)buscar.required=true;
            if (lookupToggle) {
                lookupToggle.hidden = true;
                lookupToggle.setAttribute('aria-expanded', 'true');
            }
            return;
        }
        heading.textContent = recognized.length === 1
            ? 'Posible institución identificada'
            : 'El número coincide con varias instituciones';
        identityBox.appendChild(heading);
        const hint = document.createElement('small');
        hint.textContent = recognized.length === 1
            ? 'Confirma con quien llama el nombre antes de transferir.'
            : 'Confirma a cuál institución corresponde la llamada.';
        identityBox.appendChild(hint);
        for (const record of recognized) {
            const action = document.createElement('button');
            action.type = 'button';
            action.textContent = record.nombre + ' · ' +
                record.estado_nombre + ' · ' + record.responsable +
                ' (Ext. ' + record.extension + ')';
            action.setAttribute('aria-label', 'Confirmar ' + record.nombre);
            action.addEventListener('click', () => chooseInstitution(record));
            identityBox.appendChild(action);
        }
        const reject=document.createElement('button');reject.type='button';
        reject.textContent='No corresponde a estas instituciones';
        reject.addEventListener('click',()=>{
            clearInstitution();rejectedRecognitionForCall=currentCallId;recognized=[];
            renderIdentification([],call()?.phone||'');
            if(lookupWrap)lookupWrap.hidden=false;if(buscar){buscar.value='';buscar.required=true;}
            setFeedback('Origen sin expediente confirmado. Selecciona el destino y escribe el nombre de quien llama.');
        });
        identityBox.appendChild(reject);
        // La confirmación ya realizada se conserva durante ESTA llamada
        // cuando Tania explora Prospecto y vuelve a Institución; únicamente
        // se recupera si el servidor sigue devolviendo ese mismo expediente
        // y un destino autorizado. No se extiende a llamadas posteriores.
        const sameConfirmed = confirmedRecord?.callId === currentCallId &&
            recognized.find(record =>
                Number(record.seguimiento_id) === confirmedRecord.seguimientoId &&
                Number(record.responsable_id) === confirmedRecord.usuarioId &&
                Number(record.estado_id) === confirmedRecord.estadoId);
        if (sameConfirmed) {
            chooseInstitution(sameConfirmed);
        } else if (recognized.length === 1) {
            // Sugerencia automática sin asumir identidad ni transferir.
            const record = recognized[0];
            if (estado) estado.value = String(record.estado_id);
            const item = targetEntries.find(x =>
                Number(x.usuario_id) === Number(record.responsable_id) &&
                Number(x.estado_id) === Number(record.estado_id));
            renderRecipients(item ? keyFor(item) : '');
        }
    };
    const renderMatches = records => {
        if (!coincidencias || !lista) return;
        lista.replaceChildren();
        coincidencias.hidden = !Array.isArray(records) || !records.length;
        (records || []).forEach(record => {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'impe-routing-match';
            button.textContent = record.nombre + ' · ' + record.responsable +
                ' · Ext. ' + record.extension;
            button.addEventListener('click', () => {
                chooseInstitution(record);
            });
            lista.appendChild(button);
        });
    };
    const load = async () => {
        const active = call();
        if (!panel || !active || panel.hidden) return;
        const seq = ++searchSequence;
        const sameCall = String(active.id || '') === currentCallId;
        if (!sameCall) {
            currentCallId = String(active.id || '');
            phoneCheckedForCall = '';
            recognized = [];
            phoneSuggestions = [];
            confirmedRecognition = false;
            confirmedRecord = null;if(buscar){buscar.value='';buscar.required=false;}
            if (identityBox) identityBox.hidden = true;
        }
        const type = categoria === 'RECEPCION' ? tipo.value : '';
        const stateId = categoria === 'RECEPCION' ? estado.value : '';
        const text = String(buscar?.value || '').trim();
        const phone = categoria === 'RECEPCION' &&
            type === 'INSTITUCION'
            ? String(active.phone || '') : '';
        const checkPhone = phone && phoneCheckedForCall !== currentCallId;
        setFeedback('Consultando responsables autorizados…');
        try {
            // El nombre del prospecto no es un filtro de responsables.
            // Los asesores autorizados se seleccionan por territorio.
            const directoryQuery = '';
            const [directory, matches] = await Promise.all([
                fetch(endpoint('routingListUrl', {
                    tipo:type, estado_id:'',
                    buscar:directoryQuery,
                    telefono_origen:checkPhone ? phone : ''
                }), {credentials:'same-origin',cache:'no-store'}).then(readJson),
                type === 'INSTITUCION' && text.length >= 2
                    ? fetch(endpoint('routingListUrl', {
                        tipo:type, estado_id:stateId, buscar:text
                    }), {credentials:'same-origin',cache:'no-store'}).then(readJson)
                    : Promise.resolve(null)
            ]);
            if (seq !== searchSequence || panel.hidden ||
                call()?.id !== active.id) return;
            // Para sugerir un destino por teléfono es necesario disponer
            // de todos los Analistas permitidos, no sólo los de un estado.
            targetEntries = directory.destinos || [];
            if (categoria === 'RECEPCION' && estado &&
                estado.options.length <= 1) {
                (directory.estados || []).forEach(item =>
                    estado.appendChild(option(item.id, item.nombre)));
            }
            if (checkPhone || (categoria==='RECEPCION' && type==='INSTITUCION' && phoneCheckedForCall!==currentCallId)) {
                phoneCheckedForCall = currentCallId;
                phoneSuggestions=directory.identificacion||[];
                renderIdentification(phoneSuggestions, phone);
            }
            renderRecipients();
            renderMatches(matches?.instituciones || []);
            const count = permitted().length;
            if (recognized.length && !confirmedRecognition) {
                setFeedback('Confirma la institución sugerida por el número entrante.');
            } else if (!count) {
                setFeedback(categoria === 'VENTAS'
                    ? 'No tienes Cuenta Clave vinculado con extensión activa.'
                    : 'No hay responsable autorizado para esta selección.', true);
            } else if (count === 1) {
                setFeedback('Responsable sugerido. Verifica el nombre antes de transferir.');
            } else {
                setFeedback(count + ' responsables disponibles. Selecciona uno.');
            }
            positionSidecar(panel);
        } catch (err) {
            if (seq !== searchSequence) return;
            targetEntries = [];
            selected = null;
            renderRecipients();
            renderMatches([]);
            setFeedback(err?.message || 'No se pudo cargar el directorio.', true);
        }
    };
    const reload = () => {
        confirmDirect = '';
        // Invalidar inmediatamente consultas iniciadas antes de un cambio
        // de categoría o estado, no sólo cuando termina el debounce.
        searchSequence++;
        window.clearTimeout(timer);
        timer = window.setTimeout(load, 240);
    };
    toggle?.addEventListener('click', () => {
        openSection(panel, toggle, capturePanel, captureToggle);
        if (!panel.hidden) {
            const source=document.querySelector('[data-availability-source]');
            if(source)panel.appendChild(source);
            void window.IMPE_AVAILABILITY?.tick?.();
            void load();
        }
    });
    $('[data-routing-close]')?.addEventListener('click', () =>
        closeSection(panel, toggle));
    phonePanel?.addEventListener('click', event => {
        if (event.target.closest('[data-incoming-minimize]')) {
            closeSection(panel, toggle);
            closeSection(capturePanel, captureToggle);
        }
    });
    window.addEventListener('keydown', event => {
        if (event.key === 'Escape') {
            closeSection(panel, toggle);
            closeSection(capturePanel, captureToggle);
        }
    });
    captureToggle?.addEventListener('click', () =>
        openSection(capturePanel, captureToggle, panel, toggle));
    lookupToggle?.addEventListener('click', () => {
        if (!lookupWrap || categoria !== 'RECEPCION') return;
        lookupWrap.hidden = !lookupWrap.hidden;
        lookupToggle.setAttribute('aria-expanded', String(!lookupWrap.hidden));
        if (!lookupWrap.hidden) buscar?.focus({preventScroll:true});
        positionSidecar(panel);
    });
    tipo?.addEventListener('change', () => {
        if (estado) estado.value='';
        if (buscar) buscar.value='';
        const esProspecto = categoria === 'RECEPCION' && tipo?.value === 'PROSPECTO';
        if (lookupWrap) lookupWrap.hidden = !esProspecto;
        if (lookupToggle) {
            lookupToggle.hidden = esProspecto;
            lookupToggle.setAttribute('aria-expanded', String(esProspecto));
        }
        if (buscar) {
            buscar.required = esProspecto;
            buscar.placeholder = esProspecto
                ? 'Nombre del prospecto o persona que llama'
                : 'Escribe el nombre si el teléfono no coincide';
        }
        if (lookupLabel) lookupLabel.textContent = esProspecto
            ? 'Nombre del prospecto o de quien llama'
            : 'Nombre de la institución o persona que llama';
        recognized = [];
        confirmedRecognition = false;
        // Volver a Institución debe reidentificar el número de ESTA llamada,
        // no heredar la marca "ya consultado" de Prospecto.
        phoneCheckedForCall = '';
        if (identityBox) identityBox.hidden = true;
        reload();
    });
    estado?.addEventListener('change', () => {
        recognized = [];
        confirmedRecognition = false;
        confirmedRecord = null;
        if (identityBox) identityBox.hidden = true;
        renderRecipients();
        reload();
    });
    buscar?.addEventListener('input', reload);
    destinos?.addEventListener('change', () => {
        selected = targetEntries.find(item => keyFor(item) === destinos.value) || null;
        if(estado)estado.value=selected?String(selected.estado_id):'';
        if(tipo?.value==='INSTITUCION'){if(confirmedRecord&&buscar)buscar.value='';if(buscar)buscar.required=true;if(lookupWrap)lookupWrap.hidden=false;}
        // Una selección manual reemplaza la sugerencia sin identificar.
        recognized = [];
        confirmedRecognition = false;
        confirmedRecord = null;
        if (identityBox) identityBox.hidden = true;
        confirmDirect = '';
    });
    const submitTransfer = async mode => {
        const active = call();
        if (transferBusy) return;
        if(window.IMPE_AVAILABILITY?.pending()){setFeedback('Ya hay una solicitud para esta llamada. Revisa el aviso del teléfono.',true);return;}
        if(active?.transferRequest && !['DEVUELTA','FALLIDA'].includes(active.transferRequest.stage)){
            setFeedback('Ya hay una transferencia en curso para esta llamada.',true);return;
        }
        if (!active || !selected) {
            setFeedback('Selecciona un responsable con extensión autorizada.', true);
            return;
        }
        if (categoria === 'RECEPCION' && !estado.value) {
            setFeedback('Confirma primero el estado de la institución o prospecto.', true);
            return;
        }
        if (recognized.length && !confirmedRecognition) {
            setFeedback('Confirma primero la institución que coincide con el número.', true);
            return;
        }
        if(categoria==='RECEPCION' && tipo.value==='INSTITUCION' && !confirmedRecognition && String(buscar?.value||'').trim().length<2){
            if(lookupWrap)lookupWrap.hidden=false;setFeedback('Escribe el nombre de la institución o persona que llama.',true);buscar?.focus();return;
        }
        if(categoria==='RECEPCION' && tipo.value==='PROSPECTO' && String(buscar?.value||'').trim().length<2){
            if(lookupWrap)lookupWrap.hidden=false;
            setFeedback('Registra el nombre del prospecto o de quien llama antes de consultar disponibilidad.',true);
            buscar?.focus();return;
        }
        const identity = active.id;
        if (!identity) {
            setFeedback('Aún no se identifica la llamada activa. Espera unos segundos.', true);
            return;
        }
        const buttons = panel.querySelectorAll('[data-routing-attended], [data-routing-direct]');
        transferBusy = true;
        buttons.forEach(btn => { btn.disabled = true; });
        try {
            const body = new URLSearchParams({
                csrf_token:String(cfg.routingCsrf || ''),
                extension:selected.extension,
                estado_id:String(categoria === 'RECEPCION' ? selected.estado_id : 0),
                tipo:categoria === 'RECEPCION' ? tipo.value : '',
                responsable_id:String(selected.usuario_id),
                modo:'DIRECTA',intencion:'SOLICITAR',
                seguimiento_id:String(
                    categoria === 'RECEPCION' && tipo.value === 'INSTITUCION' &&
                    confirmedRecognition && confirmedRecord?.callId === identity &&
                    Number(confirmedRecord.usuarioId) === Number(selected.usuario_id) &&
                    Number(confirmedRecord.estadoId) === Number(selected.estado_id)
                        ? confirmedRecord.seguimientoId : 0
                ),
                institucion_nombre:String(buscar?.value||'').trim(),
                numero_origen:String(active.phone || ''),
                referencia_llamada:identity
            });
            const data = await fetch(cfg.routingAuthorizeUrl, {
                method:'POST', credentials:'same-origin', cache:'no-store',
                headers:{
                    Accept:'application/json',
                    'Content-Type':'application/x-www-form-urlencoded;charset=UTF-8'
                },body:body.toString()
            }).then(readJson);
            if(![call()?.id,...(call()?.aliases||[])].map(String).includes(String(identity))){
                if(data.solicitud)void window.IMPE_AVAILABILITY?.cancel(data.solicitud);
                throw new Error('La llamada cambió. No se enviará ninguna transferencia.');
            }
            if(!data.solicitud||!window.IMPE_AVAILABILITY)throw new Error('No se pudo preparar la solicitud. Recarga el CRM.');
            window.IMPE_AVAILABILITY.watch(data.solicitud);
            const source=document.querySelector('[data-availability-source]');
            if(source)panel.appendChild(source);
            setFeedback('');
            positionSidecar(panel);
        } catch (err) {
            setFeedback(err?.message || 'No se pudo autorizar la transferencia.', true);
        } finally {
            transferBusy = false;
            buttons.forEach(btn => { btn.disabled = false; });
        }
    };
    $('[data-routing-attended]')?.addEventListener('click', () =>
        void submitTransfer('DIRECTA'));
    $('[data-routing-direct]')?.addEventListener('click', () =>
        void submitTransfer('DIRECTA'));

    if (categoria !== 'RECEPCION') {
        $('[data-routing-type-wrap]')?.setAttribute('hidden', '');
        $('[data-routing-state-wrap]')?.setAttribute('hidden', '');
        $('[data-routing-search-wrap]')?.setAttribute('hidden', '');
        if (lookupToggle) lookupToggle.hidden = true;
    }

    // Nota temporal por llamada. Nunca se considera verificada ni se crea
    // automáticamente un expediente por llenar esta ficha.
    const captureForm = $('[data-routing-capture-form]');
    const captureMsg = $('[data-routing-capture-message]');
    const drafts=window.IMPE_CALL_DRAFTS;
    let draftCallId='',draftRefs=[],draftDirty=false,draftCycle=0,draftTimer=null;
    const values=()=>Object.fromEntries(['nombre','persona','telefono','correo','notas','contacto_nombre','contacto_cargo']
        .map(name=>[name,String(captureForm.elements.namedItem(name)?.value||'').trim()]));
    const save=async()=>{
        if(!draftCallId||!drafts||!captureForm)return;
        const cycle=draftCycle,ids=[...draftRefs],data=values();
        if(captureMsg)captureMsg.textContent='Guardando borrador…';
        try {
            await drafts.save(Number(cfg.userId),ids,data,String(cfg.routingCsrf||''));
            if(cycle===draftCycle&&captureMsg)captureMsg.textContent='Borrador guardado. Revisa el resultado al finalizar.';
        } catch (_) {
            if(cycle===draftCycle&&captureMsg)captureMsg.textContent='Conservado en este navegador. Pendiente de guardar en el servidor; vuelve a intentarlo.';
        }
    };
    const updateDraftContext=async()=>{
        const current=call();if(!current?.id||!captureForm)return;
        const ids=drafts?.refs([current.id,...(current.aliases||[])])||[current.id];
        if(draftRefs.some(id=>ids.includes(id))){draftRefs=ids;draftCallId=current.id;return;}
        const cycle=++draftCycle;clearTimeout(draftTimer);draftCallId=current.id;draftRefs=ids;draftDirty=false;captureForm.reset();
        if(captureMsg)captureMsg.textContent='Recuperando borrador…';
        const saved=await drafts?.read(Number(cfg.userId),ids);
        if(cycle!==draftCycle||draftDirty)return;
        if(saved)for(const [name,value] of Object.entries(drafts?.captureValues?.(saved)||saved)) {
            const input=captureForm.elements.namedItem(name);if(input)input.value=String(value||'');
        }
        if(captureMsg)captureMsg.textContent=saved?'Borrador recuperado.':'';
    };
    captureToggle?.addEventListener('click',()=>void updateDraftContext());
    captureForm?.addEventListener('input',()=>{
        const current=call();
        if(current && draftRefs.some(id=>[current.id,...(current.aliases||[])].includes(id))) {
            draftRefs=drafts?.refs([current.id,...(current.aliases||[])])||draftRefs;
            draftCallId=current.id;
        }
        draftDirty=true;
        drafts?.localSave(Number(cfg.userId),draftRefs,values());clearTimeout(draftTimer);draftTimer=setTimeout(save,700);
    });
    captureForm?.addEventListener('submit',event=>{event.preventDefault();clearTimeout(draftTimer);void save();});
    window.addEventListener('pagehide',()=>{if(draftDirty)void save();});
})();
