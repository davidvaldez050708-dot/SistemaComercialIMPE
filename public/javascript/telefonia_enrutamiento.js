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
    const categoria = cfg.analystIncomingResults
        ? 'ANALISTA'
        : cfg.canCapture ? 'VENTAS' : 'RECEPCION';
    let targetEntries = [];
    let visibleCall = null;
    let selected = null;
    let timer = 0;
    let searchSequence = 0;
    let confirmDirect = '';
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
        if (opening) pane.querySelector('input, select')?.focus({preventScroll:true});
    };

    const renderRecipients = () => {
        if (!destinos) return;
        const previous = destinos.value;
        destinos.replaceChildren(option('', 'Selecciona un responsable'));
        targetEntries.forEach(item => {
            const key = String(item.usuario_id) + ':' +
                String(item.extension) + ':' + String(item.estado_id);
            const label = [item.nombre, 'Ext. '+item.extension,
                item.estado_nombre, item.rol].filter(Boolean).join(' · ');
            destinos.appendChild(option(key, label));
        });
        if ([...destinos.options].some(x => x.value === previous)) destinos.value = previous;
        selected = targetEntries.find(item =>
            String(item.usuario_id)+':'+item.extension+':'+item.estado_id === destinos.value) || null;
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
                const assigned = targetEntries.find(x =>
                    Number(x.usuario_id) === Number(record.responsable_id) &&
                    x.extension === String(record.extension));
                if (!assigned) return;
                destinos.value = String(assigned.usuario_id)+':'+
                    assigned.extension+':'+assigned.estado_id;
                selected = assigned;
                setFeedback('Expediente existente: responsable '+assigned.nombre+'.');
            });
            lista.appendChild(button);
        });
    };
    const load = async () => {
        if (!panel || !call()) return;
        const seq = ++searchSequence;
        const type = categoria === 'RECEPCION' ? tipo.value : '';
        const stateId = categoria === 'RECEPCION' ? estado.value : '';
        const text = String(buscar?.value || '').trim();
        setFeedback('Consultando responsables autorizados…');
        try {
            // Para instituciones: el campo de búsqueda encuentra expedientes,
            // pero no puede ocultar la lista de responsables del estado.
            const directoryQuery = type === 'INSTITUCION' ? '' : text;
            const [directory, matches] = await Promise.all([
                fetch(endpoint('routingListUrl', {
                    tipo:type, estado_id:stateId, buscar:directoryQuery
                }), {credentials:'same-origin',cache:'no-store'}).then(readJson),
                type === 'INSTITUCION' && stateId && text.length >= 2
                    ? fetch(endpoint('routingListUrl', {
                        tipo:type, estado_id:stateId, buscar:text
                    }), {credentials:'same-origin',cache:'no-store'}).then(readJson)
                    : Promise.resolve(null)
            ]);
            if (seq !== searchSequence || !call()) return;
            targetEntries = directory.destinos || [];
            if (categoria === 'RECEPCION' && estado &&
                estado.options.length <= 1) {
                (directory.estados || []).forEach(item =>
                    estado.appendChild(option(item.id, item.nombre)));
            }
            renderRecipients();
            renderMatches(matches?.instituciones || []);
            const resultText = targetEntries.length
                ? targetEntries.length + ' responsable(s) disponible(s) por asignación.'
                : categoria === 'VENTAS'
                    ? 'Sin Cuenta Clave vinculado a tu asignación comercial. Solicita su configuración.'
                    : 'No hay extensión receptora autorizada en este estado o equipo.';
            setFeedback(resultText, !targetEntries.length);
        } catch (err) {
            if (seq !== searchSequence) return;
            targetEntries = [];
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
    captureToggle?.addEventListener('click', () =>
        openSection(capturePanel, captureToggle, panel, toggle));
    tipo?.addEventListener('change', () => {
        if (estado) estado.value='';
        if (buscar) buscar.value='';
        reload();
    });
    estado?.addEventListener('change', reload);
    buscar?.addEventListener('input', reload);
    destinos?.addEventListener('change', () => {
        selected = targetEntries.find(item =>
            String(item.usuario_id)+':'+item.extension+':'+item.estado_id === destinos.value
        ) || null;
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
    }

    // Nota temporal por llamada. Nunca se considera verificada ni se crea
    // automáticamente un expediente por llenar esta ficha.
    const captureForm = $('[data-routing-capture-form]');
    const captureMsg = $('[data-routing-capture-message]');
    const keyFor = id => 'impe:crm:call-draft:'+Number(cfg.userId)+':'+id;
    let draftCallId = '';
    const updateDraftContext = () => {
        const id = call()?.id || '';
        if (id && id !== draftCallId && captureForm) {
            draftCallId = id;
            captureForm.reset();
            try {
                const saved = JSON.parse(sessionStorage.getItem(keyFor(id)) || 'null');
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
            sessionStorage.setItem(keyFor(current.id), JSON.stringify(values));
            if (captureMsg) captureMsg.textContent =
                'Borrador guardado para esta llamada. Confirma el resultado al finalizar.';
        } catch (_) {
            if (captureMsg) captureMsg.textContent =
                'El navegador no pudo guardar el borrador.';
        }
    });
})();
