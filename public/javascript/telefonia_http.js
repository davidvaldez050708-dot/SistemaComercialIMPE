/* Peticiones de telefonía: plazo completo, concurrencia y cancelación por llamada. */
(function (root, factory) {
    'use strict';
    if (typeof module === 'object' && module.exports) module.exports = {create: factory};
    if (root && !root.IMPE_PHONE_HTTP) root.IMPE_PHONE_HTTP = factory(root);
})(typeof window !== 'undefined' ? window : null, function (view) {
    'use strict';
    const pending = new Map();
    const totals = {sent: 0, completed: 0, timeouts: 0, aborted: 0, coalesced: 0};
    let sequence = 0;
    let api;
    let flushing = null;
    let deliveryPauseUntil = 0;
    let blockedCsrf = null;
    const deliveryFailures = new Map();
    const userId = () => Number(view.IMPE_PHONE_SHELL_CONFIG?.userId ||
        view.IMPE_TELEPHONY_HOST?.userId || view.IMPE_CURRENT_USER_ID || 0);
    function parentBroker() {
        try {
            const parent = view.parent;
            if (parent && parent !== view && parent.location.origin === view.location.origin) {
                const broker = parent.IMPE_PHONE_HTTP;
                if (broker && broker !== api && userId() > 0 && broker.userId === userId()) return broker;
            }
        } catch (_) { /* Otra página no comparte el contexto autenticado. */ }
        return null;
    }
    function csrfToken() {
        const broker = parentBroker();
        if (broker) return broker.csrfToken();
        try {
            const frame = view.document?.getElementById('impeCrmFrame')?.contentWindow;
            if (frame && frame.location.origin === view.location.origin) {
                const owner = Number(frame.IMPE_CURRENT_USER_ID || 0);
                if (owner && owner !== userId()) return '';
                if (!owner && new URL(frame.location.href).searchParams.get('controller') === 'login') return '';
                if (owner === userId() && frame.IMPE_CSRF_TOKEN) return String(frame.IMPE_CSRF_TOKEN);
            }
        } catch (_) { return ''; }
        return String(view.IMPE_CSRF_TOKEN || view.IMPE_TELEPHONY_HOST?.transferenciaCsrf ||
            view.IMPE_PHONE_SHELL_CONFIG?.routingCsrf || '');
    }
    const queuePrefix = owner => 'impe.phone.sdk.outbox.v2.' + owner + '.';
    function queuedSessions(owner) {
        const storage = view.localStorage;
        const prefix = queuePrefix(owner);
        const entries = [];
        for (let i = 0; i < storage.length; i++) {
            const key = storage.key(i);
            if (!key?.startsWith(prefix)) continue;
            try {
                const raw = storage.getItem(key);
                const event = JSON.parse(raw);
                if (key !== prefix + event.session_id + '.' + event.secuencia ||
                    !/^sdk-[a-z0-9-]{12,94}$/.test(event.session_id) ||
                    !Number.isSafeInteger(event.secuencia) || event.secuencia < 1) continue;
                entries.push({key, raw, event});
            } catch (_) { /* Conservar entradas dañadas para diagnóstico; nunca borrarlas. */ }
        }
        return entries.sort((a,b) => a.event.session_id.localeCompare(b.event.session_id) ||
            a.event.secuencia - b.event.secuencia);
    }
    function enqueueSession(data) {
        const broker = parentBroker();
        if (broker) return broker.enqueueSession(data);
        const owner = userId();
        if (owner < 1 || !/^sdk-[a-z0-9-]{12,94}$/.test(String(data?.session_id || '')) ||
            !Number.isSafeInteger(data.secuencia) || data.secuencia < 1 || data.secuencia > 2147483647 ||
            !['ringing','dialed','answered','ended','declined','missed','failed','transfer_link','successor_link'].includes(data.evento))
            throw new Error('Evento local de telefonía inválido.');
        // Campos explícitos: nunca persistir cookies, CSRF, credenciales ni datos comerciales.
        const event = {session_id:data.session_id, secuencia:data.secuencia, evento:data.evento};
        for (const field of ['direccion','numero','caller_name','sdk_id','sdk_dialogo','predecesora_session_id','replaces_dialogo','requires_session','duracion_observada_segundos']) {
            if (data[field] !== undefined && data[field] !== null) {
                if (field === 'duracion_observada_segundos' ?
                    !Number.isInteger(data[field]) || data[field] < 0 || data[field] > 86400 :
                    typeof data[field] !== 'string') throw new Error('Campo de sesión local inválido.');
                event[field] = data[field];
            }
        }
        if(data.evento==='transfer_link'||data.evento==='successor_link'){
            for(const field of ['operacion_id','intento']){
                if(!Number.isSafeInteger(data[field])||data[field]<1)throw new Error('Operación de transferencia inválida.');
                event[field]=data[field];
            }
        }
        if(data.evento==='successor_link'){
            if(!Number.isSafeInteger(data.requires_sequence)||data.requires_sequence<1)throw new Error('Dependencia SDK inválida.');
            event.requires_sequence=data.requires_sequence;
        }
        if (JSON.stringify(event).length > 4096) throw new Error('Evento local demasiado grande.');
        const key = queuePrefix(owner) + event.session_id + '.' + event.secuencia;
        const raw = JSON.stringify(event);
        const previous = view.localStorage.getItem(key);
        if (previous !== null && previous !== raw) throw new Error('La secuencia local ya contiene otro evento.');
        // Una clave por evento: ninguna pestaña reescribe la cola de otra llamada.
        if (previous === null) view.localStorage.setItem(key, raw);
        return {session_id:event.session_id, secuencia:event.secuencia};
    }
    // Una observación por diálogo SDK. No interpreta estados PBX ni formularios.
    function dialogIdentity(session){
        const id=session?._dialog?.id;
        if(!id||!['call_id','local_tag','remote_tag'].every(k=>typeof id[k]==='string'&&/^[!-~]{1,180}$/.test(id[k])))return null;
        return {call_id:id.call_id,local_tag:id.local_tag,remote_tag:id.remote_tag};
    }
    function replacesIdentity(request){
        const parsed=request?.parseHeader?.('replaces');
        if(!parsed||![parsed.call_id,parsed.to_tag,parsed.from_tag].every(v=>typeof v==='string'&&/^[!-~]{1,180}$/.test(v)))return null;
        return {call_id:parsed.call_id,local_tag:parsed.to_tag,remote_tag:parsed.from_tag};
    }
    function createSessionRecorder(context) {
        const owner=userId(),id=String(context?.session_id||''),direction=context?.direccion;
        if(owner<1||!/^sdk-[a-z0-9-]{12,94}$/.test(id)||!['ENTRANTE','SALIENTE'].includes(direction))
            throw new Error('Contexto local de sesión inválido.');
        const number=String(context.numero||''),name=String(context.caller_name||'');
        let sequence=0,started=false,answered=false,closed=false,sdkId='',answeredAt=0,transferKey='',dialog='',successorKey='',transferRoot='';
        const emit=(event,extra={})=>{
            if(owner!==userId())throw new Error('La observación pertenece a otro usuario.');
            const data={session_id:id,secuencia:sequence+1,evento:event,direccion:direction,
                numero:number,caller_name:name,...extra};
            if(sdkId)data.sdk_id=sdkId;
            if(dialog)data.sdk_dialogo=dialog;
            enqueueSession(data);sequence++;
        };
        const start=()=>{
            if(closed)return;
            if(!started){emit(direction==='SALIENTE'?'dialed':'ringing');started=true;}
        };
        return Object.freeze({
            session_id:id,
            bind(value,identity=null){
                const next=String(value||'');
                if(!/^[A-Za-z0-9@._:+-]{1,180}$/.test(next)||(sdkId||closed)&&sdkId!==next)
                    throw new Error('El diálogo SDK no corresponde a esta observación.');
                sdkId=next;
                if(identity){const raw=JSON.stringify(identity);
                    if(dialog&&dialog!==raw)throw new Error('Cambió la identidad del diálogo SIP.');
                    dialog=raw;
                }
            },
            start,
            answered(){
                if(closed||answered)return;
                start();emit('answered');answered=true;answeredAt=Date.now();
            },
            end(reason='ended'){
                if(closed)return;
                if(!['ended','failed','declined','missed'].includes(reason))throw new Error('Cierre local inválido.');
                // Fallar antes de existir un diálogo no acredita un marcado SDK.
                if(started||direction!=='SALIENTE'||reason!=='failed')start();
                emit(reason,answered?{duracion_observada_segundos:Math.min(86400,Math.max(0,Math.floor((Date.now()-answeredAt)/1000)))}:{});
                closed=true;
            },
            transfer(operation){
                if(direction!=='ENTRANTE'||!started||!answered||!sdkId||
                    operation?.estado!=='ENTREGADA'||operation.sdk_consulta!==id)return;
                const operationId=Number(operation.id),attempt=Number(operation.intento),key=operationId+':'+attempt;
                if(key===transferKey)return;
                emit('transfer_link',{operacion_id:operationId,intento:attempt});transferKey=key;
            },
            successor(previous,operation,replaces){
                if(direction!=='ENTRANTE'||!answered||!sdkId||!dialog||!previous?.sdk_dialogo||
                    !previous.answered||previous.session_id===id||operation?.estado!=='ENTREGADA'||
                    operation.sdk_consulta!==(previous.transfer_root||previous.session_id))return;
                const key=previous.session_id+':'+operation.id+':'+operation.intento;
                if(successorKey===key)return;
                emit('successor_link',{predecesora_session_id:previous.session_id,replaces_dialogo:JSON.stringify(replaces),
                    operacion_id:Number(operation.id),intento:Number(operation.intento),
                    requires_session:previous.session_id,requires_sequence:previous.secuencia});
                successorKey=key;transferRoot=operation.sdk_consulta;
            },
            snapshot:()=>({session_id:id,secuencia:sequence,started,answered,closed,sdk_id:sdkId,sdk_dialogo:dialog,transfer_root:transferRoot})
        });
    }
    function flushSessions(endpoint) {
        const broker = parentBroker();
        if (broker) return broker.flushSessions(endpoint);
        if (flushing) return flushing;
        const url = new URL(String(endpoint), view.location.href);
        if (url.origin !== view.location.origin || !url.pathname.endsWith('/prueba_telefonia/api/sesion_local.php'))
            return Promise.reject(new Error('Destino de sesiones no permitido.'));
        const owner = userId();
        const deliver = async () => {
            const stats = {acknowledged:0, retained:0};
            if (Date.now() < deliveryPauseUntil) return stats;
            const blocked = new Set();
            let attempted = 0;
            for (const entry of queuedSessions(owner)) {
                if (owner !== userId() || attempted >= 8 || view.navigator?.onLine === false) break;
                const {key, raw, event} = entry;
                if(event.requires_session&&queuedSessions(owner).some(item=>item.event.session_id===event.requires_session&&
                    item.event.secuencia<=event.requires_sequence&&view.localStorage.getItem(item.key)!==null)){
                    blocked.add(event.session_id);stats.retained++;continue;
                }
                const prior = deliveryFailures.get(key);
                if (blocked.has(event.session_id)) continue;
                if (prior && prior.retryAt > Date.now()) {blocked.add(event.session_id); continue;}
                const csrf = csrfToken();
                if (!csrf || csrf === blockedCsrf) break;
                attempted++;
                let status = 0;
                try {
                    const response = await request(url.href, {method:'POST',
                        headers:{'Content-Type':'application/json','Accept':'application/json'},
                        body:JSON.stringify({...event, accion:'registrar', csrf_token:csrf})});
                    status = response.status;
                    if (status === 401 || status === 403) blockedCsrf = csrf;
                    const data = await response.json();
                    const row = data?.sesion;
                    const acknowledged = Number(row?.ultima_secuencia);
                    if (!response.ok || data?.ok !== true || row?.session_id !== event.session_id ||
                        !Number.isSafeInteger(acknowledged) || acknowledged < event.secuencia) throw new Error('SESSION_ACK_PENDING');
                    if (owner !== userId()) break;
                    // Sólo eliminar el evento exacto confirmado, jamás el siguiente.
                    if (view.localStorage.getItem(key) === raw) view.localStorage.removeItem(key);
                    deliveryFailures.delete(key);
                    stats.acknowledged++;
                    if(typeof view.dispatchEvent==='function'&&typeof view.CustomEvent==='function')
                        view.dispatchEvent(new view.CustomEvent('impe:phone-journal-ack',
                            {detail:{userId:owner,sessionId:event.session_id,sequence:event.secuencia}}));
                } catch (_) {
                    const count = (prior?.count || 0) + 1;
                    deliveryFailures.set(key, {count, retryAt:Date.now() + Math.min(60000, 1000 * 2 ** Math.min(count,6))});
                    blocked.add(event.session_id);
                    stats.retained++;
                    if (status === 401 || status === 403) break;
                    if (!status || status === 429 || status >= 500) {
                        deliveryPauseUntil = Date.now() + Math.min(60000, 1000 * 2 ** Math.min(count,6));
                        break;
                    }
                    // No adelantar ANSWER/END de esa sesión. Otra llamada independiente sí puede avanzar.
                }
            }
            return stats;
        };
        // Sin Web Locks, los duplicados entre pestañas son idempotentes en SQL.
        const locks = view.navigator?.locks;
        flushing = Promise.resolve().then(() => locks?.request ?
            locks.request('impe-phone-session-delivery-' + owner, {ifAvailable:true}, lock =>
                lock ? deliver() : {acknowledged:0, retained:0}) : deliver()).finally(() => {flushing = null;});
        return flushing;
    }
    function failure(code) {
        const error = new Error(code === 'REQUEST_TIMEOUT' ? 'El servidor no respondió a tiempo.' : 'La consulta ya no pertenece a la llamada actual.');
        error.name = code === 'REQUEST_TIMEOUT' ? 'TimeoutError' : 'AbortError';
        error.code = code;
        return error;
    }
    function request(input, options = {}) {
        const owner = userId();
        const broker = parentBroker();
        if (broker) return broker.request(input, options).then(response => {
            if (owner !== userId()) throw failure('STALE_USER');
            return response;
        });
        const url = new URL(String(input), view.location.href);
        if (url.origin !== view.location.origin) return Promise.reject(new Error('Destino telefónico no permitido.'));
        const {timeoutMs = 12000, scope = '', coalesceKey = '', ...settings} = options;
        const method = String(settings.method || 'GET').toUpperCase();
        const headers = new view.Headers(settings.headers || {});
        if (owner > 0) headers.set('X-Impe-Phone-User', String(owner));
        headers.set('X-Impe-Phone-Protocol', '2');
        const identity = [owner, method, url.href, String(scope), headers.get('Accept'), headers.get('Range')].join('|');
        const key = method === 'GET' ? identity : coalesceKey ? identity + '|' + coalesceKey : 'mutation:' + (++sequence);
        const previous = pending.get(key);
        if (previous) {totals.coalesced++; return previous.promise.then(replay);}
        const entry = {scope: String(scope), method, cancel: null, cancelRequested: false, promise: null};
        entry.promise = Promise.resolve().then(async function () {
            const controller = typeof view.AbortController === 'function' ? new view.AbortController() : null;
            const external = settings.signal;
            let timer;
            let finished = false;
            let rejectGate;
            const gate = new Promise((_, reject) => {rejectGate = reject;});
            function cleanup() {
                if (finished) return;
                finished = true;
                if (timer !== undefined) view.clearTimeout(timer);
                external?.removeEventListener?.('abort', externalAbort);
                if (pending.get(key) === entry) pending.delete(key);
                entry.cancel = null;
            }
            function stop(code) {
                if (finished) return;
                if (code === 'REQUEST_TIMEOUT') totals.timeouts++; else totals.aborted++;
                cleanup();
                controller?.abort();
                rejectGate(failure(code));
            }
            const externalAbort = () => stop('REQUEST_ABORTED');
            try {
                if (entry.cancelRequested || external?.aborted) throw failure('REQUEST_ABORTED');
                entry.cancel = () => stop('STALE_CALL');
                external?.addEventListener?.('abort', externalAbort, {once: true});
                timer = view.setTimeout(() => stop('REQUEST_TIMEOUT'), Math.max(10, Math.min(120000, Number(timeoutMs) || 12000)));
                totals.sent++;
                const response = await Promise.race([
                    view.fetch(url.href, {...settings, method, headers, credentials: 'same-origin', ...(controller ? {signal: controller.signal} : {})}), gate
                ]);
                const body = await Promise.race([response.arrayBuffer(), gate]);
                if (owner !== userId()) throw failure('STALE_USER');
                totals.completed++;
                cleanup();
                return {body, status: response.status, statusText: response.statusText,
                    headers: response.headers, url: response.url || url.href, redirected: response.redirected,
                    type: response.type};
            } catch (error) {
                cleanup();
                throw error;
            }
        });
        pending.set(key, entry);
        return entry.promise.then(replay);
    }
    function replay(wire) {
        // Cada consumidor recibe su propio cuerpo: una vista no puede modificar
        // el JSON ni consumir la respuesta que pertenece a otra vista.
        const response = new view.Response([204, 205, 304].includes(wire.status) ? null : wire.body.slice(0),
            {status: wire.status, statusText: wire.statusText, headers: new view.Headers(wire.headers)});
        for (const field of ['url', 'redirected', 'type']) {
            if (wire[field] !== undefined) Object.defineProperty(response, field, {value: wire[field]});
        }
        return response;
    }
    function cancelScope(scope) {
        const broker = parentBroker();
        if (broker) return broker.cancelScope(scope);
        if (!scope) return;
        for (const entry of pending.values()) {
            // Una mutación puede haber sido confirmada por el servidor aunque se cambie de llamada.
            if (entry.method !== 'GET' || entry.scope !== String(scope)) continue;
            entry.cancelRequested = true;
            entry.cancel?.();
        }
    }
    function createSnapshotGate(expectedUser) {
        let current = null;
        let legacyTime = 0;
        const retired = new Set();
        return function accept(value) {
            if (!value || Number(value.userId) !== Number(expectedUser)) return false;
            const authority = value.authority;
            if (!authority) {
                if (current || Number(value.updatedAt || 0) < legacyTime) return false;
                legacyTime = Number(value.updatedAt || 0);
                return true;
            }
            if (typeof authority.host !== 'string' || !authority.host ||
                !Number.isSafeInteger(authority.sequence) || authority.sequence < 1 ||
                !Number.isSafeInteger(authority.epoch) || authority.epoch<0 || retired.has(authority.host)) return false;
            if (current?.host === authority.host && authority.sequence <= current.sequence) return false;
            if (current && current.host !== authority.host) {
                if (authority.epoch < current.epoch) return false;
                retired.add(current.host);
                if (retired.size > 32) retired.delete(retired.values().next().value);
            }
            current = {...authority};
            return true;
        };
    }
    api = Object.freeze({request, cancelScope, get userId() {return userId();},
        createSnapshotGate, csrfToken, enqueueSession, flushSessions, createSessionRecorder, dialogIdentity, replacesIdentity,
        snapshot() {const broker = parentBroker();return broker ? broker.snapshot() : {...totals, pending: pending.size};}});
    return api;
});
