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
    const phonePanel = $('[data-incoming-panel]');
    // Sacar las fichas del panel telefónico para no modificar su tamaño,
    // posición guardada, arrastre ni los controles de colgar/silenciar.
    [panel, capturePanel].filter(Boolean).forEach(pane => {
        pane.classList.add('impe-routing-sidecar');
        document.body.appendChild(pane);
    });
    const positionSidecar = pane => {
        if (!pane || pane.hidden) return;
        const rect = phonePanel?.getBoundingClientRect();
        const width = Math.min(420, window.innerWidth - 16);
        const margin = 10;
        let x = Math.max(8, window.innerWidth - width - 8);
        let y = 8;
        if (rect) {
            const rightSpace = window.innerWidth - rect.right;
            const leftSpace = rect.left;
            if (rightSpace >= width + margin) x = rect.right + margin;
            else if (leftSpace >= width + margin) x = rect.left - width - margin;
            else x = Math.max(8, (window.innerWidth - width) / 2);
            y = Math.min(Math.max(8, rect.top),
                Math.max(8, window.innerHeight - Math.min(530, window.innerHeight - 16) - 8));
        }
        pane.style.left = Math.round(x) + 'px';
        pane.style.top = Math.round(y) + 'px';
        pane.style.right = 'auto';
        pane.style.bottom = 'auto';
    };
    window.addEventListener('resize', () => {
        positionSidecar(panel);
        positionSidecar(capturePanel);
    });
    window.addEventListener('pointerup', () => {
        positionSidecar(panel);
        positionSidecar(capturePanel);
    });
    const categoria = cfg.analystIncomingResults
        ? 'ANALISTA'
        : cfg.canCapture ? 'VENTAS' : 'RECEPCION';
    let targetEntries = [];
    let selected = null;
    let timer = 0;
    let searchSequence = 0;
    let confirmDirect = '';
    let recognized = [];
    let confirmedRecognition = false;
    let phoneCheckedForCall = '';
    let currentCallId = '';
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
    const permitted = () => {
        const id = categoria === 'RECEPCION' ? String(estado?.value || '') : '';
        return targetEntries.filter(item => !id || String(item.estado_id) === id);
    };
    const renderRecipients = (preferred = '') => {
        if (!destinos) return;
        const previous = preferred || destinos.value;
        const visible = permitted();
        destinos.replaceChildren(option('', 'Seleccionar responsable'));
        visible.forEach(item => {
            const label = [item.nombre, 'Ext. '+item.extension,
                item.estado_nombre, item.rol].filter(Boolean).join(' · ');
            destinos.appendChild(option(keyFor(item), label));
        });
        const chosen = visible.find(item => keyFor(item) === previous) ||
            (visible.length === 1 ? visible[0] : null);
        destinos.value = chosen ? keyFor(chosen) : '';
        selected = chosen;
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
        confirmedRecognition = true;
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
            identityBox.hidden = false;
        }
        setFeedback('Responsable listo. Confirma verbalmente la transferencia.');
    };
    const renderIdentification = (records, phone) => {
        if (!identityBox) return;
        identityBox.replaceChildren();
        identityBox.hidden = false;
        recognized = Array.isArray(records) ? records : [];
        confirmedRecognition = false;
        const heading = document.createElement('strong');
        if (!recognized.length) {
            heading.textContent = 'Número sin expediente identificado';
            identityBox.appendChild(heading);
            const hint = document.createElement('small');
            hint.textContent = 'Pregunta el estado y selecciona al responsable. La búsqueda por nombre es opcional.';
            identityBox.appendChild(hint);
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
        if (recognized.length === 1) {
            // Sugerir en automático sin asumir que un teléfono compartido
            // verifica la identidad. La confirmación requiere un clic.
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
                chooseInstitution({...record, estado_id:Number(estado?.value || 0)});
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
            confirmedRecognition = false;
            if (identityBox) identityBox.hidden = true;
        }
        const type = categoria === 'RECEPCION' ? tipo.value : '';
        const stateId = categoria === 'RECEPCION' ? estado.value : '';
        const text = String(buscar?.value || '').trim();
        const phone = categoria === 'RECEPCION' &&
            type === 'INSTITUCION' && active.direction === 'incoming'
            ? String(active.phone || '') : '';
        const checkPhone = phone && phoneCheckedForCall !== currentCallId;
        setFeedback('Consultando responsables autorizados…');
        try {
            const directoryQuery = type === 'INSTITUCION' ? '' : text;
            const [directory, matches] = await Promise.all([
                fetch(endpoint('routingListUrl', {
                    tipo:type, estado_id:checkPhone ? '' : stateId,
                    buscar:directoryQuery,
                    telefono_origen:checkPhone ? phone : ''
                }), {credentials:'same-origin',cache:'no-store'}).then(readJson),
                type === 'INSTITUCION' && stateId && text.length >= 2
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
            if (checkPhone) {
                phoneCheckedForCall = currentCallId;
                renderIdentification(directory.identificacion || [], phone);
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
        window.clearTimeout(timer);
        timer = window.setTimeout(load, 240);
    };
    toggle?.addEventListener('click', () => {
        openSection(panel, toggle, capturePanel, captureToggle);
        if (!panel.hidden) void load();
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
        recognized = [];
        confirmedRecognition = false;
        phoneCheckedForCall = currentCallId; // Tipo comercial no reidentifica institución.
        if (identityBox) identityBox.hidden = true;
        reload();
    });
    estado?.addEventListener('change', () => {
        recognized = [];
        confirmedRecognition = false;
        if (identityBox) identityBox.hidden = true;
        renderRecipients();
        reload();
    });
    buscar?.addEventListener('input', reload);
    destinos?.addEventListener('change', () => {
        selected = targetEntries.find(item => keyFor(item) === destinos.value) || null;
        // Una selección manual reemplaza la sugerencia sin identificar.
        recognized = [];
        confirmedRecognition = false;
        if (identityBox) identityBox.hidden = true;
        confirmDirect = '';
    });
    const submitTransfer = async mode => {
        const active = call();
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
        const identity = active.id;
        if (!identity) {
            setFeedback('Aún no se identifica la llamada activa. Espera unos segundos.', true);
            return;
        }
        if (mode === 'DIRECTA') {
            const key = identity+':'+selected.extension;
            if (confirmDirect !== key) {
                confirmDirect = key;
                setFeedback('Transferencia directa: pulsa nuevamente para confirmar '+
                    selected.nombre+' (ext. '+selected.extension+').');
                return;
            }
        }
        const buttons = panel.querySelectorAll('[data-routing-attended], [data-routing-direct]');
        buttons.forEach(btn => { btn.disabled = true; });
        try {
            const body = new URLSearchParams({
                csrf_token:String(cfg.routingCsrf || ''),
                extension:selected.extension,
                estado_id:String(categoria === 'RECEPCION' ? selected.estado_id : 0),
                tipo:categoria === 'RECEPCION' ? tipo.value : '',
                responsable_id:String(selected.usuario_id),
                modo:mode
            });
            const data = await fetch(cfg.routingAuthorizeUrl, {
                method:'POST', credentials:'same-origin', cache:'no-store',
                headers:{
                    Accept:'application/json',
                    'Content-Type':'application/x-www-form-urlencoded;charset=UTF-8'
                },body:body.toString()
            }).then(readJson);
            if (call()?.id !== identity ||
                !api.requestAuthorizedTransfer(data.token)) {
                throw new Error('La llamada cambió; no se solicitó ninguna transferencia.');
            }
            setFeedback('Solicitud enviada al teléfono. Espera la confirmación de Zadarma; no cuelgues todavía.');
            confirmDirect = '';
        } catch (err) {
            setFeedback(err?.message || 'No se pudo autorizar la transferencia.', true);
        } finally {
            buttons.forEach(btn => { btn.disabled = false; });
        }
    };
    $('[data-routing-attended]')?.addEventListener('click', () =>
        void submitTransfer('CONSULTADA'));
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
    const draftKeyFor = id => 'impe:crm:call-draft:'+Number(cfg.userId)+':'+id;
    let draftCallId = '';
    const updateDraftContext = () => {
        const id = call()?.id || '';
        if (id && id !== draftCallId && captureForm) {
            draftCallId = id;
            captureForm.reset();
            try {
                const saved = JSON.parse(sessionStorage.getItem(draftKeyFor(id)) || 'null');
                if (saved && Date.now() - Number(saved.saved_at || 0) < 86400000) {
                    ['nombre','persona','telefono','correo','notas'].forEach(name => {
                        if (captureForm.elements.namedItem(name))
                            captureForm.elements.namedItem(name).value =
                                String(saved[name] || '');
                    });
                }
            } catch (_) {}
            if (captureMsg) captureMsg.textContent='';
        }
    };
    captureToggle?.addEventListener('click', updateDraftContext);
    captureForm?.addEventListener('submit', event => {
        event.preventDefault();
        const current = call();
        if (!current?.id) {
            if (captureMsg) captureMsg.textContent='No hay llamada activa identificable.';
            return;
        }
        const values = {saved_at:Date.now(), call_id:current.id};
        ['nombre','persona','telefono','correo','notas'].forEach(name =>
            values[name] = String(captureForm.elements.namedItem(name)?.value || '').trim());
        try {
            sessionStorage.setItem(draftKeyFor(current.id), JSON.stringify(values));
            if (captureMsg) captureMsg.textContent =
                'Borrador guardado para esta llamada. Confirma el resultado al finalizar.';
        } catch (_) {
            if (captureMsg) captureMsg.textContent =
                'El navegador no pudo guardar el borrador.';
        }
    });
})();
