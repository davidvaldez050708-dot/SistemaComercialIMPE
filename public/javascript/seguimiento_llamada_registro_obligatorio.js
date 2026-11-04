/* La obligación pertenece a la llamada exacta, no a un modal ni a un toast. */
(function () {
    'use strict';
    document.addEventListener('DOMContentLoaded', function () {
        const panel = document.getElementById('offcanvasSeguimientoTrabajo');
        const phone = window.IMPE_TELEPHONY_PERSISTENT;
        const user = Number(window.IMPE_CURRENT_USER_ID || 0);
        if (Number(window.IMPE_CURRENT_ROLE_ID) !== 4 || !panel || !phone || !user) return;
        const key = 'impe:analista:registro-obligatorio:' + user;
        const validRef = value => /^[a-zA-Z0-9_-]{6,100}$/.test(String(value || ''));
        const form = () => panel.querySelector('[data-work-interaction-form]');
        let pending = null, restoring = false, lastNotice = 0;
        let completed = [];
        let phoneHome = null;
        const workspaceCss = document.createElement('style');
        workspaceCss.textContent = `
          #offcanvasSeguimientoTrabajo.impe-call-workspace {
            left:50%!important;right:auto!important;top:50%!important;bottom:auto!important;
            transform:translate(-50%,-50%)!important;width:920px!important;max-width:calc(100vw - 32px)!important;
            height:calc(100vh - 48px)!important;max-height:960px;border:1px solid #dce5f2;
            border-radius:20px;box-shadow:0 20px 60px rgba(20,35,58,.18);background:#fff;
            font-family:Manrope,sans-serif;overflow:hidden;
          }
          .impe-call-workspace,.impe-call-workspace * {font-family:Manrope,sans-serif!important;}
          .impe-call-workspace>.offcanvas-header {padding:22px 28px;border-bottom:1px solid #dce5f2;background:#fff;}
          .impe-call-workspace>.offcanvas-header>div:not([data-call-workspace-heading]) {display:none;}
          [data-call-workspace-heading] {display:none;}
          .impe-call-workspace [data-call-workspace-heading] {display:block;}
          .impe-call-workspace [data-call-workspace-heading] h5 {font-size:22px;font-weight:800;line-height:1.35;margin:0 0 6px;color:#233654;}
          .impe-call-workspace [data-call-workspace-heading] p {font-size:13px;color:#7385a3;margin:0;}
          .impe-call-workspace>.offcanvas-body {padding:24px 28px;overflow:auto;overflow-anchor:none;background:#fff;}
          .impe-call-workspace>.offcanvas-body>:not([data-call-workspace-keep]) {display:none!important;}
          .impe-call-workspace .linkage-work-section {margin:0 0 20px;padding:20px;border:1px solid #dce5f2;border-radius:16px;}
          .impe-call-workspace .linkage-work-section-title {margin-bottom:16px;}
          .impe-call-workspace .linkage-work-contact-grid,.impe-call-workspace [data-work-verify-row],
          .impe-call-workspace [data-work-contact-form]>.linkage-work-source-row,
          .impe-call-workspace [data-work-contact-form]>.linkage-work-form-title {display:none;}
          .impe-call-workspace .linkage-work-form {border:0;padding:0;}
          .impe-call-workspace .form-label {font-size:13px;font-weight:700;color:#425979;margin-bottom:8px;}
          .impe-call-workspace .form-control,.impe-call-workspace .form-select {font-family:inherit;font-size:14px;border-color:#d5e0ef;border-radius:12px;min-height:48px;}
          .impe-call-workspace .linkage-work-form-actions {position:sticky;bottom:-24px;background:#fff;border-top:1px solid #e1e8f2;padding:18px 0 8px;margin-top:20px;z-index:2;}
          .impe-call-workspace [data-work-cancel-interaction],.impe-call-workspace [data-work-toggle-interaction] {display:none!important;}
          .impe-call-workspace [data-persistent-phone] {position:static!important;transform:none!important;width:100%!important;max-width:none!important;margin:0 0 20px!important;box-shadow:none!important;border:0;border-radius:0;flex:0 0 auto!important;overflow:visible;}
          .impe-call-workspace [data-persistent-phone] .persistent-phone-header,
          .impe-call-workspace [data-phone-compact-actions],.impe-call-workspace [data-phone-result] {display:none!important;}
          .impe-call-workspace [data-persistent-phone] .persistent-phone-body {display:block!important;padding:0!important;}
          .impe-call-workspace [data-phone-start] {display:none!important;}
          .impe-call-workspace [data-phone-active-actions] {display:flex;flex-wrap:wrap;gap:12px;}
          .impe-call-workspace [data-phone-active-actions][hidden] {display:none!important;}
          .impe-call-workspace [data-phone-active-actions]>.persistent-phone-action {height:auto;min-height:48px;flex:1;padding:12px 18px;}
          .impe-call-workspace [data-audio-before-call] {flex-basis:100%;width:100%;font-size:13px;}
          .impe-call-workspace [data-audio-before-call][hidden] {display:none!important;}
          .linkage-call-required-note {font-family:Manrope,sans-serif;font-size:13px!important;line-height:1.55;font-weight:500;color:#345782;background:#f0f5fc;border:1px solid #d9e5f6;border-left:3px solid #009d8b;border-radius:12px;padding:12px 16px;margin:0 0 20px;}
          .linkage-call-required-note button {margin-top:10px;font-size:13px;}
          @media(max-width:575px) {
            #offcanvasSeguimientoTrabajo.impe-call-workspace {max-width:calc(100vw - 16px)!important;height:calc(100vh - 16px)!important;border-radius:16px;}
            .impe-call-workspace>.offcanvas-header {padding:18px;}
            .impe-call-workspace>.offcanvas-body {padding:16px;}
            .impe-call-workspace .linkage-work-section {padding:14px;}
            .impe-call-workspace [data-call-workspace-heading] h5 {font-size:19px;}
          }
          @media(prefers-reduced-motion:reduce) {#offcanvasSeguimientoTrabajo.impe-call-workspace{transition:none!important;}}
        `;
        document.head.appendChild(workspaceCss);
        const workHeader=panel.querySelector('.offcanvas-header');
        const workspaceHeading=document.createElement('div');
        workspaceHeading.dataset.callWorkspaceHeading='';
        const workspaceTitle=document.createElement('h5'),workspaceSubtitle=document.createElement('p');
        workspaceHeading.append(workspaceTitle,workspaceSubtitle);workHeader?.prepend(workspaceHeading);
        const mountWorkspace=()=>{
            const body=panel.querySelector('.offcanvas-body');
            if(!body||!pending)return;
            const entering=!panel.classList.contains('impe-call-workspace');
            form()?.closest('section')?.setAttribute('data-call-workspace-keep','');
            panel.querySelector('[data-work-contact-form]')?.closest('section')?.setAttribute('data-call-workspace-keep','');
            panel.querySelector('[data-work-alert]')?.setAttribute('data-call-workspace-keep','');
            const telephone=document.querySelector('[data-persistent-phone]');
            if(telephone && !panel.contains(telephone)){
                phoneHome=document.createComment('Ubicación del teléfono institucional');
                telephone.before(phoneHome);telephone.dataset.callWorkspaceKeep='';body.prepend(telephone);
            }
            workspaceTitle.textContent=pending.finished?'Registrar resultado de llamada':'Llamada institucional · Captura de datos';
            workspaceSubtitle.textContent=panel.querySelector('[data-work-title]')?.textContent||'Expediente institucional';
            panel.classList.add('impe-call-workspace');
            if(entering) window.requestAnimationFrame(()=>{body.scrollTop=0;});
        };
        const unmountWorkspace=()=>{
            panel.classList.remove('impe-call-workspace');
            const telephone=panel.querySelector('[data-persistent-phone]');
            if(telephone && phoneHome){phoneHome.replaceWith(telephone);delete telephone.dataset.callWorkspaceKeep;}
            phoneHome=null;
        };
        try {
            const stored = JSON.parse(sessionStorage.getItem(key + ':closed') || '[]');
            if (Array.isArray(stored)) completed = stored.filter(validRef).slice(-100);
        } catch (_) { /* El vínculo exacto también limpia el pendiente en memoria. */ }
        try {
            const stored = JSON.parse(sessionStorage.getItem(key) || 'null');
            if (stored && stored.user === user && validRef(stored.token) &&
                Number(stored.seguimientoId) > 0) pending = stored;
        } catch (_) { /* También se recupera el estado del teléfono persistente. */ }
        const persist = () => {
            try {
                if (pending) sessionStorage.setItem(key, JSON.stringify(pending));
                else sessionStorage.removeItem(key);
            } catch (_) { /* El bloqueo en memoria sigue activo. */ }
            document.body.classList.toggle('impe-call-registration-pending', Boolean(pending));
            document.dispatchEvent(new CustomEvent('impe:call-registration-pending-changed', {
                detail: {pending: Boolean(pending), seguimientoId: pending?.seguimientoId || 0}
            }));
        };
        const notify = () => {
            if (Date.now() - lastNotice < 1400) return;
            lastNotice = Date.now();
            window.IMPE_TELEPHONY_NOTIFY?.(pending?.interactionId
                ? 'El resultado está guardado. Espera la vinculación de esta llamada antes de salir.'
                : 'Registra el resultado de esta llamada antes de cerrar el panel o cambiar de módulo.', true);
        };
        const samePanel = () => Number(panel.dataset.flowSeguimientoId) === Number(pending?.seguimientoId);
        const capture = event => {
            if (event && !event.isTrusted) return;
            if (!pending || !samePanel() || restoring || pending.interactionId) return;
            const values = {};
            for (const input of form()?.elements || []) {
                // Nunca conservar identidad, permisos, CSRF ni metadata técnica del formulario.
                if (!input.name || ['hidden','submit','button','file'].includes(input.type)) continue;
                values[input.name] = input.type === 'checkbox' ? (input.checked ? '1' : '0') : input.value;
            }
            pending.values = values;
            const contact=panel.querySelector('[data-work-contact-form]');
            if(contact && (!contact.classList.contains('d-none') || pending.contactValues)){
                pending.contactValues={};
                for(const input of contact.elements){
                    if(input.name && !['hidden','submit','button','file'].includes(input.type))
                        pending.contactValues[input.name]=input.type==='checkbox'?(input.checked?'1':'0'):input.value;
                }
            }
            persist();
        };
        const restore = () => {
            const target = form();
            if (!pending || !samePanel() || !target || pending.interactionId) return;
            restoring = true;
            for (const [name, value] of Object.entries(pending.values || {})) {
                const input = target.elements.namedItem(name);
                if (!input || ['hidden','file'].includes(input.type)) continue;
                if (input.type === 'checkbox') input.checked = value === '1';
                else input.value = value;
            }
            target.querySelector('[name="resultado"]')?.dispatchEvent(new Event('change', {bubbles:true}));
            const contact=panel.querySelector('[data-work-contact-form]');
            if(contact && pending.contactValues){
                for(const [name,value] of Object.entries(pending.contactValues)){
                    const input=contact.elements.namedItem(name);
                    if(!input||['hidden','file'].includes(input.type))continue;
                    if(input.type==='checkbox')input.checked=value==='1';else input.value=value;
                }
                contact.classList.remove('d-none');
            }
            restoring = false;
        };
        const showForm = () => {
            if (!pending) return;
            if (!samePanel()) { phone.openContext(true); return; }
            const target = form();
            if (!target) return;
            const result=target.querySelector('[name="resultado"]');
            if(result && !Object.prototype.hasOwnProperty.call(pending.values||{},'resultado') &&
                !result.querySelector('[data-call-result-placeholder]')){
                const placeholder=document.createElement('option');
                placeholder.value='';placeholder.textContent='Selecciona lo que ocurrió';placeholder.disabled=true;
                placeholder.dataset.callResultPlaceholder='';result.prepend(placeholder);result.value='';
                result.dispatchEvent(new Event('change',{bubbles:true}));
            }
            if (target.classList.contains('d-none')) panel.querySelector('[data-work-toggle-interaction]')?.click();
            target.classList.remove('d-none');
            mountWorkspace();
            window.bootstrap?.Offcanvas.getOrCreateInstance(panel).show();
            let note = target.querySelector('[data-call-registration-required-note]');
            if (!note) {
                note = document.createElement('div');
                note.className = 'linkage-call-required-note';
                note.dataset.callRegistrationRequiredNote = '';
                note.setAttribute('role', 'status');
                target.prepend(note);
            }
            note.textContent = pending.interactionId
                ? 'Resultado guardado. Vinculación telefónica pendiente; no vuelvas a registrar la interacción.'
                : pending.finished ? 'La llamada terminó. Registra el resultado para completar su seguimiento.'
                : 'Puedes completar los datos y las observaciones durante la llamada. Al finalizar, guarda el resultado.';
            if (pending.interactionId) {
                const retry = document.createElement('button');
                retry.type = 'button'; retry.className = 'btn btn-system-cancel';
                retry.textContent = 'Reintentar vinculación';
                retry.addEventListener('click', () => {
                    document.dispatchEvent(new CustomEvent('impe:interaction-exact-id-ready', {
                        detail: {seguimientoId: pending.seguimientoId, interaccionId: pending.interactionId}
                    }));
                });
                note.append(retry);
            }
            const channel = target.querySelector('select[name="canal"]');
            if (channel) {
                channel.value = 'LLAMADA'; channel.disabled = true;
                if (!target.querySelector('[data-call-locked-channel]')) {
                    const hidden = document.createElement('input');
                    hidden.type = 'hidden'; hidden.name = 'canal'; hidden.value = 'LLAMADA';
                    hidden.dataset.callLockedChannel = ''; target.append(hidden);
                }
            }
            const submit = target.querySelector('[type="submit"]');
            if (submit) submit.disabled = !pending.finished || Boolean(pending.interactionId);
            restore();
        };
        const observe = state => {
            if (!state || state.context?.type !== 'VINCULACION') return;
            const id = Number(state.context.seguimientoId || 0);
            const token = String(state.callToken || state.finalMetadata?.call_token || '');
            if (!id || !validRef(token) || completed.includes(token) ||
                (!state.active && state.phase !== 'finished')) return;
            // No sustituir un pendiente por una notificación tardía de otra llamada.
            if (pending && pending.token !== token) return;
            const finished = state.phase === 'finished' && !state.active;
            const wasFinished = pending?.finished;
            const firstObservation=!pending;
            if (!pending) pending = {user, token, seguimientoId:id, values:{}, interactionId:0};
            pending.pbx = String(state.finalMetadata?.pbx_call_id || state.pbxCallId || pending.pbx || '');
            pending.finished = finished;
            persist();
            if(firstObservation && state.active) window.setTimeout(showForm,0);
            if (finished && !wasFinished) {
                // El puente prepara primero la fecha y el resumen de ESTA llamada.
                phone.openContext(true);
                window.setTimeout(showForm, 250);
            }
        };
        phone.subscribe(observe);
        observe(phone.getState());
        if (pending) { persist(); window.setTimeout(showForm, 300); }
        panel.addEventListener('input', capture);
        panel.addEventListener('change', capture);
        document.addEventListener('impe:call-result-form-opened', () => window.setTimeout(showForm, 0));
        document.addEventListener('impe:flow-updated', () => { if (pending) window.setTimeout(showForm, 0); });
        form()?.addEventListener('reset', () => { if (pending) window.setTimeout(restore, 0); });
        const block = event => {
            event.preventDefault(); event.stopImmediatePropagation();
            notify();
            if (pending?.finished) showForm();
        };
        panel.addEventListener('hide.bs.offcanvas', event => { if (pending) block(event); });
        const preventNavigation = event => {
            if (!pending || event.defaultPrevented) return;
            const target = event.target.closest?.('a[href], [data-work-follow], [data-work-call-button], [data-work-cancel-interaction]');
            if (!target) return;
            if (target.matches('[data-work-follow]') && Number(target.dataset.workFollowId) === pending.seguimientoId) return;
            if (target.matches('a')) {
                const href = target.getAttribute('href') || '';
                if (target.hasAttribute('download') || target.target === '_blank' || event.ctrlKey || event.metaKey ||
                    /^(#|mailto:|tel:)/i.test(href) || !href) return;
            }
            block(event);
        };
        document.addEventListener('click', preventNavigation, true);
        panel.addEventListener('submit', event => {
            if (!pending || !event.target.matches('[data-work-interaction-form]')) return;
            if (!pending.finished || pending.interactionId) {
                event.preventDefault(); event.stopImmediatePropagation();
                window.IMPE_TELEPHONY_NOTIFY?.(pending.interactionId
                    ? 'El resultado ya está guardado. Espera la vinculación telefónica.'
                    : 'Puedes llenar la captura durante la llamada. Finalízala antes de guardar su resultado.', true);
            }
        }, true);
        const beforeUnload = event => {
            if (!pending) return;
            capture(); event.preventDefault(); event.returnValue = '';
        };
        window.addEventListener('beforeunload', beforeUnload);
        // El contenedor permanece al navegar el iframe: proteger también su recarga.
        try {
            if (window.parent !== window) {
                window.parent.document.addEventListener('click', preventNavigation, true);
                window.parent.addEventListener('beforeunload', beforeUnload);
                window.addEventListener('pagehide', () => {
                    window.parent.document.removeEventListener('click', preventNavigation, true);
                    window.parent.removeEventListener('beforeunload', beforeUnload);
                }, {once:true});
            }
        } catch (_) { /* Fuera del shell conserva la protección del documento. */ }
        const saved = event => {
            if (!pending?.finished || Number(event.detail?.seguimientoId) !== pending.seguimientoId ||
                !(Number(event.detail?.interaccionId) > 0)) return;
            pending.interactionId = Number(event.detail.interaccionId);
            persist(); showForm();
        };
        document.addEventListener('impe:interaction-exact-id-ready', saved);
        document.addEventListener('impe:interaction-informative-saved', saved);
        document.addEventListener('impe:telephony-call-linked', event => {
            const detail = event.detail || {};
            if (!pending || detail.provider !== 'ZADARMA' || detail.callToken !== pending.token ||
                Number(detail.seguimientoId) !== pending.seguimientoId ||
                Number(detail.interaccionId) !== pending.interactionId || !pending.interactionId) return;
            completed = [...completed, pending.token].slice(-100);
            try { sessionStorage.setItem(key + ':closed', JSON.stringify(completed)); } catch (_) {}
            pending = null; persist();unmountWorkspace();
            form()?.querySelector('[data-call-registration-required-note]')?.remove();
            form()?.querySelector('[data-call-locked-channel]')?.remove();
            form()?.querySelector('[data-call-result-placeholder]')?.remove();
            const channel = form()?.querySelector('select[name="canal"]');
            if (channel) channel.disabled = false;
            const submit = form()?.querySelector('[type="submit"]');
            if (submit) submit.disabled = false;
        });
    });
})();
