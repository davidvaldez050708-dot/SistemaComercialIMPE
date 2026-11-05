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
        // Aviso propio del Panel de trabajo; no modifica el modal telefónico.
        if (!document.getElementById('impe-linkage-call-required-style')) {
            const style = document.createElement('style');
            style.id = 'impe-linkage-call-required-style';
            style.textContent = `
                #offcanvasSeguimientoTrabajo .linkage-call-required-note {
                    display:flex;align-items:center;gap:10px;padding:10px 12px;
                    border:1px solid #e2e7ef;border-radius:9px;
                    background:#f8fafc;color:#46556b;
                    margin:0 0 14px;font:500 13px/1.45 Manrope,sans-serif;
                    box-shadow:none;min-width:0;
                }
                #offcanvasSeguimientoTrabajo .linkage-call-required-note>i {
                    color:#657992;font-size:17px;line-height:1.2;flex:none;
                }
                #offcanvasSeguimientoTrabajo .linkage-call-required-note>div {
                    display:flex;flex-direction:column;gap:2px;min-width:0;
                }
                #offcanvasSeguimientoTrabajo .linkage-call-required-note strong {
                    display:block;color:#33445c;font-size:12.5px;
                    font-weight:700;line-height:1.4;
                }
                #offcanvasSeguimientoTrabajo .linkage-call-required-note span {
                    display:block;color:#64748b;font-size:12px;
                    line-height:1.4;overflow-wrap:anywhere;
                }
                #offcanvasSeguimientoTrabajo .linkage-call-required-note button {
                    margin-left:auto;white-space:normal;flex:none;
                }
                @media (max-width:575.98px) {
                    #offcanvasSeguimientoTrabajo .linkage-call-required-note {
                        align-items:flex-start;padding:10px;
                    }
                }
            `;
            document.head.appendChild(style);
        }
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
            restoring = false;
        };
        const showForm = () => {
            // El resultado definitivo se registra sólo después de colgar.
            if (!pending || !pending.finished) return;
            if (!samePanel()) { phone.openContext(true); return; }
            const target = form();
            if (!target) return;
            if (target.classList.contains('d-none')) panel.querySelector('[data-work-toggle-interaction]')?.click();
            target.classList.remove('d-none');
            window.bootstrap?.Offcanvas.getOrCreateInstance(panel).show();
            let note = target.querySelector('[data-call-registration-required-note]');
            if (!note) {
                note = document.createElement('div');
                note.className = 'linkage-call-required-note';
                note.dataset.callRegistrationRequiredNote = '';
                note.setAttribute('role', 'status');
                const icon = document.createElement('i');
                icon.className = 'bi bi-telephone-check';
                icon.setAttribute('aria-hidden', 'true');
                const content = document.createElement('div');
                content.append(document.createElement('strong'), document.createElement('span'));
                note.append(icon, content);
                target.prepend(note);
            }
            const title = note.querySelector('strong');
            const message = note.querySelector('span');
            if (title) title.textContent = pending.interactionId
                ? 'Interacción guardada' : 'Resultado pendiente';
            if (message) message.textContent = pending.interactionId
                ? 'Vinculación telefónica pendiente. No vuelvas a registrar esta llamada.'
                : 'Selecciona el resultado y guarda la interacción en el expediente.';
            note.querySelector('[data-call-link-retry]')?.remove();
            if (pending.interactionId) {
                const retry = document.createElement('button');
                retry.type = 'button'; retry.className = 'btn btn-system-cancel';
                retry.dataset.callLinkRetry = '';
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
            if (submit && pending.interactionId) submit.disabled = true;
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
            if (!pending) pending = {user, token, seguimientoId:id, values:{}, interactionId:0};
            pending.pbx = String(state.finalMetadata?.pbx_call_id || state.pbxCallId || pending.pbx || '');
            pending.finished = finished;
            persist();
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
        let unloadOwner = window;
        // El contenedor permanece al navegar el iframe: proteger también su recarga.
        try {
            if (window.parent !== window) {
                window.parent.document.addEventListener('click', preventNavigation, true);
                unloadOwner = window.parent;
                window.parent.addEventListener('beforeunload', beforeUnload);
                window.addEventListener('pagehide', () => {
                    window.parent.document.removeEventListener('click', preventNavigation, true);
                    window.parent.removeEventListener('beforeunload', beforeUnload);
                }, {once:true});
            }
        } catch (_) { /* Fuera del shell conserva la protección del documento. */ }
        if (unloadOwner === window) window.addEventListener('beforeunload', beforeUnload);
        const saved = event => {
            if (!pending?.finished || Number(event.detail?.seguimientoId) !== pending.seguimientoId ||
                !(Number(event.detail?.interaccionId) > 0)) return;
            pending.interactionId = Number(event.detail.interaccionId);
            persist(); showForm();
        };
        document.addEventListener('impe:interaction-exact-id-ready', saved);
        document.addEventListener('impe:interaction-informative-saved', saved);
        document.addEventListener('impe:call-result-secured', event => {
            const detail = event.detail || {};
            if (!pending?.finished || detail.callToken !== pending.token ||
                Number(detail.seguimientoId) !== pending.seguimientoId ||
                !(Number(detail.interaccionId) > 0) ||
                (pending.interactionId && Number(detail.interaccionId) !== pending.interactionId)) return;
            // Liberar por guardado durable, sin inventar un vínculo ni acreditar la meta.
            pending.interactionId = Number(detail.interaccionId);
            document.dispatchEvent(new CustomEvent('impe:call-registration-complete', {detail}));
        });
        const complete = event => {
            const detail = event.detail || {};
            if (!pending || detail.callToken !== pending.token ||
                Number(detail.seguimientoId) !== pending.seguimientoId ||
                Number(detail.interaccionId) !== pending.interactionId || !pending.interactionId) return;
            completed = [...completed, pending.token].slice(-100);
            try { sessionStorage.setItem(key + ':closed', JSON.stringify(completed)); } catch (_) {}
            pending = null; persist();
            form()?.querySelector('[data-call-registration-required-note]')?.remove();
            form()?.querySelector('[data-call-locked-channel]')?.remove();
            const channel = form()?.querySelector('select[name="canal"]');
            if (channel) channel.disabled = false;
            const submit = form()?.querySelector('[type="submit"]');
            if (submit) submit.disabled = false;
        };
        document.addEventListener('impe:call-registration-complete', complete);
        document.addEventListener('impe:telephony-call-linked', event => {
            if (event.detail?.provider === 'ZADARMA') complete(event);
        });
    });
})();
