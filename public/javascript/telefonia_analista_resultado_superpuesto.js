/* Resultado de Analista independiente del iframe de navegación.
 * Sólo auricular verde e incoming WebRTC: Seguimiento conserva su propio flujo.
 */
(function (root) {
    'use strict';
    const cfg = root.IMPE_PHONE_SHELL_CONFIG || {};
    const crm = root.document.getElementById('impeCrmFrame');
    if (!cfg.analystIncomingResults || !crm) return;
    let current = null;
    const validRef = value => /^[a-zA-Z0-9_-]{3,100}$/.test(String(value || ''));
    const notify = function (message, kind = 'success') {
        message=String(message||'Resultado de llamada registrado correctamente.').slice(0,400);
        try {
            const target=crm.contentWindow;
            if(kind==='success' && target.bootstrap?.Toast && typeof target.IMPE_TELEPHONY_NOTIFY==='function'){
                target.IMPE_TELEPHONY_NOTIFY(message);return;
            }
        } catch (_) {}
        // El shell no carga Bootstrap: usar el mismo aspecto institucional
        // sin depender de navegar o recargar la pantalla de origen.
        let container=root.document.querySelector('[data-analyst-result-toasts]');
        if(!container){container=root.document.createElement('div');container.dataset.analystResultToasts='';
            container.className='toast-container';container.style.cssText='position:fixed;top:18px;right:18px;z-index:13000;max-width:calc(100vw - 36px);display:grid;gap:10px';root.document.body.append(container);}
        const toast=root.document.createElement('div');toast.className='toast system-toast show';
        toast.setAttribute('role',kind==='error'?'alert':'status');toast.setAttribute('aria-live',kind==='error'?'assertive':'polite');
        toast.style.cssText='display:flex;align-items:center;gap:12px;width:420px;max-width:100%;padding:18px 20px;border:1px solid #e1e7ef;border-radius:15px;background:#fff;box-shadow:0 12px 32px #172b4d1a;font-family:Manrope,sans-serif;font-size:14px;font-weight:700;color:#24354c';
        const icon=root.document.createElement('i');icon.className='bi '+(kind==='info'?'bi-info-circle':kind==='error'?'bi-exclamation-circle':'bi-check2-circle');
        icon.setAttribute('aria-hidden','true');icon.style.color=kind==='info'?'#304ca0':kind==='error'?'#d92d20':'#009b83';
        const text=root.document.createElement('span');text.textContent=message;toast.append(icon,text);container.append(toast);
        root.setTimeout(()=>toast.remove(),4500);
    };
    const close = function (saved, technical = false) {
        const old = current;
        if (!old || (!saved && old.required && !technical)) return false;
        current = null;
        if (!saved) {
            // Desreservar el resultado por la vía normal del formulario.
            // Destruir el iframe sin hide dejaría la telefonía bloqueada.
            try {
                old.frame.contentWindow.postMessage(
                    {type:'IMPE_CALL_RESULT_INTERRUPT'},root.location.origin);
            } catch (_) {}
            root.setTimeout(() => old.element.remove(), 180);
            old.onDismiss?.();
        } else {
            old.element.remove();
        }
        try { crm.contentWindow?.dispatchEvent(new CustomEvent('impe:analyst-result-updated')); }
        catch (_) {}
        return true;
    };
    const open = function (input, options) {
        const refs = [...new Set((options?.refs || []).filter(validRef).map(String))];
        if (!refs.length) return false;
        const url = new URL(String(input), root.location.href);
        const controller = url.searchParams.get('controller');
        const action = url.searchParams.get('action');
        const required=options?.required===true || url.searchParams.has('saliente_ref');
        if (url.origin !== root.location.origin ||
            !((controller === 'seguimientoVinculacion' && action === 'llamadasEntrantes') ||
              (controller === 'telefonia' && action === 'misLlamadas' &&
               url.searchParams.get('vista') === 'recibidas'))) return false;
        if (current) {
            if (refs.some(ref => current.refs.includes(ref))) return true;
            return false;
        }
        url.searchParams.set('impe_result_overlay','1');
        const layer = root.document.createElement('div');
        layer.dataset.analystResultOverlay = '';
        layer.setAttribute('role','dialog');
        layer.setAttribute('aria-modal','true');
        layer.setAttribute('aria-label','Registrar resultado de llamada');
        layer.style.cssText = 'position:fixed;inset:0;z-index:12010;background:rgba(20,35,58,.46);font-family:Manrope,sans-serif';
        const frame = root.document.createElement('iframe');
        frame.title = 'Formulario de resultado de llamada';
        frame.style.cssText = 'display:block;position:absolute;inset:0;width:100%;height:100%;border:0;background:transparent';
        const dismiss = root.document.createElement('button');
        dismiss.type = 'button';
        dismiss.textContent = 'Volver a mi pantalla · resultado pendiente';
        dismiss.style.cssText = 'position:absolute;top:8px;right:18px;z-index:2;border:1px solid #cbd5e1;background:#fff;color:#344054;border-radius:9px;font-size:12px;font-weight:700;padding:7px 12px;box-shadow:0 3px 12px #10203e20;cursor:pointer';
        dismiss.addEventListener('click', () => close(false));
        layer.append(frame);
        if(!required)layer.append(dismiss);
        root.document.body.appendChild(layer);
        current = {element:layer,frame,refs,onSaved:options.onSaved,onDismiss:options.onDismiss,
            onUncertain:options.onUncertain,required,prepare:options.prepare||null,ready:null};
        frame.addEventListener('load', function () {
            if (!current || current.frame !== frame) return;
            try {
                const doc = frame.contentDocument;
                const dest = new URL(frame.contentWindow.location.href);
                if (dest.origin !== root.location.origin ||
                    !(dest.searchParams.has('sdk_session_id') || dest.searchParams.has('saliente_ref'))) {
                    close(false,true);return;
                }
                const style = doc.createElement('style');
                style.textContent = [
                  'html,body{background:transparent!important;overflow:hidden!important}',
                  'body>*:not(script):not(style){visibility:hidden!important}',
                  '#modalLlamadaEntranteAnalista,#modalLlamadaEntranteAnalista *,',
                  '.modal-backdrop{visibility:visible!important}',
                  '#modalLlamadaEntranteAnalista .modal-dialog{max-height:calc(100vh - 28px)}'
                ].join('');
                doc.head.appendChild(style);
                if(current.prepare)frame.contentWindow.postMessage({type:'IMPE_ANALYST_OUTGOING_PREPARE',...current.prepare},root.location.origin);
                if(current.ready)frame.contentWindow.postMessage({type:'IMPE_ANALYST_OUTGOING_READY',...current.ready},root.location.origin);
            } catch (_) { close(false,true); }
        });
        frame.src = url.href;
        return true;
    };
    root.addEventListener('message', function (event) {
        const active = current;
        if (!active || event.origin !== root.location.origin ||
            event.source !== active.frame.contentWindow) return;
        const callId = String(event.data?.callId || '');
        if (!active.refs.includes(callId)) return;
        if (event.data?.type === 'IMPE_ANALYST_RESULT_SAVED') {
            active.onSaved?.(callId);
            if (current === active) {close(true);notify(event.data.message);}
        } else if (event.data?.type === 'IMPE_ANALYST_OUTGOING_SAVE_UNCERTAIN') {
            active.onUncertain?.(callId);
        }
    });
    root.IMPE_ANALYST_RESULT_OVERLAY = Object.freeze({
        open, dismiss: () => close(false),
        notify,
        ready: (token, ref) => {
            if(!current || !current.refs.includes(String(token)) || !validRef(ref))return false;
            if(!current.refs.includes(ref))current.refs.push(ref);
            current.ready={token,ref};
            current.frame.contentWindow.postMessage({type:'IMPE_ANALYST_OUTGOING_READY',token,ref},root.location.origin);
            return true;
        },
        finish: id => {
            if (!current || !current.refs.includes(String(id))) return false;
            close(true);notify('El resultado de esta llamada ya está guardado.');return true;
        },
        isOpen: () => Boolean(current),
        hasRef: id => Boolean(current && current.refs.includes(String(id)))
    });
    // La recepción conserva prioridad sin destruir el formulario ni su captura.
    root.setInterval(()=>{
        if(current?.required)current.element.style.display=root.IMPE_TELEPHONY_SHELL?.getCallState?.()?.active?'none':'block';
    },200);
})(window);
