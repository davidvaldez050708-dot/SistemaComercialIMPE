#!/usr/bin/env bash
# Parche PINNED SHA256: shell Hostinger 45519 + tres archivos SIP/ventas.
# No modifica SQL, PHP, credenciales ni archivos ajenos.
set -euo pipefail
umask 077
IMPE_PUBLISHER_PATH="$(realpath "$0")" python3 - "$@" <<'PY'
import hashlib, json, os, sys, shutil, tempfile, datetime, re
from pathlib import Path

TAG = "impe-sip-fin-pbx-20261021-ventas-compat-v4"
PATCHES = json.loads(r'''[{"path":"public/javascript/telefonia_sdk_entrantes_observador.js","pairs":[["        let observedSession = null;\n        let domRinging = false;\n","        let observedSession = null;\n        const listenedSessions = new WeakSet();\n        let domRinging = false;\n"],["        const reportIdle = function () {\n            ringing = false;\n            acceptanceRequestedUntil = 0;\n            if (!idleReported) {\n                idleReported = true;\n                hooks.onIdle?.({sessionEnded:Boolean(observedSession?.isEnded()),pushEnded:Boolean(!observedSession&&!connectedReported&&win.zdrmWebPhone?.callState===''&&!read()),sessionId:String(observedSession?.id||'')});\n            }\n        };\n\n","        const reportIdle = function (terminalEvidence = false, endedSession = null) {\n            ringing = false;\n            acceptanceRequestedUntil = 0;\n            if (!idleReported) {\n                idleReported = true;\n                hooks.onIdle?.({\n                    sessionEnded: Boolean(terminalEvidence || observedSession?.isEnded()),\n                    pushEnded: Boolean(!observedSession && !connectedReported &&\n                        win.zdrmWebPhone?.callState === '' && !read()),\n                    sessionId: String(endedSession?.id || observedSession?.id || '')\n                });\n            }\n        };\n        // La instancia de Zadarma puede retirar webCallSession antes del\n        // siguiente muestreo (250 ms). Conservar el END real de la RTCSession\n        // mediante sus eventos públicos, sin alterar métodos del proveedor.\n        const watchSession = function (session) {\n            if (!session || listenedSessions.has(session) ||\n                typeof session.on !== 'function') return;\n            listenedSessions.add(session);\n            const ended = function () {\n                if (stopped || !seenIncoming ||\n                    !sameSession(session, observedSession)) return;\n                reportIdle(true, session);\n            };\n            session.on('ended', ended);\n            session.on('failed', ended);\n        };\n\n"],["                observedSession = session && !session.isEnded() ? session : null;\n","                observedSession = session && !session.isEnded() ? session : null;\n                watchSession(observedSession);\n"],["                observedSession = session;\n            }\n","                observedSession = session;\n                watchSession(observedSession);\n            }\n"],["            isConnected: function () { return connectedReported && !idleReported; },\n","            isConnected: function () { return connectedReported && !idleReported; },\n            checkNow: check,\n"]]},{"path":"public/javascript/telefonia_host.js","pairs":[["            if(state.direction==='incoming'){\n                if(incomingBridge?.canHangup())incomingBridge.hangup();\n                void pollIncoming();\n            }else void poll();\n","            if(state.direction==='incoming'){\n                // Consultar no es volver a colgar: el usuario ya pidió\n                // finalizar y repetir finishCall puede cancelar otro diálogo.\n                analystSdkObserver?.checkNow?.();\n                void pollIncoming();\n            }else void poll();\n"],["        } else if (action === 'HANGUP') {\n            void hangup();\n","        } else if (action === 'PBX_CONFIRMED_INCOMING_END') {\n            // La capa comercial consultó el detalle autorizado del PBX.\n            // No emitir END a Zadarma, ni asignarlo a una sesión nueva.\n            const ref=String(payload.ref||''),token=String(payload.callToken||'');\n            const ownRef=String(state.pbxCallId||state.context?.transferReference||'');\n            if(state.active&&state.direction==='incoming'&&\n                ['recovering','finishing'].includes(state.phase)&&\n                /^[A-Za-z0-9_-]{6,100}$/.test(ref)&&\n                token!==''&&token===state.callToken&&ownRef===ref){\n                const seconds=Number(payload.seconds);\n                const duration=Number.isInteger(seconds)&&seconds>=0\n                    ? seconds:currentDuration();\n                answeredAtMs=0;\n                retirePbxCall(ref);\n                publish({active:false,phase:'incoming-finished',\n                    pbxCallId:ref,status:'completed',duration,\n                    context:{...state.context,pbxEnded:true,\n                        pbxAnswered:Boolean(payload.connected)},\n                    message:'Cierre PBX confirmado para esta llamada.'});\n                ocultarHostEntrante();\n                marcarPestanaEntrante('');\n            }\n        } else if (action === 'HANGUP') {\n            void hangup();\n"]]},{"path":"public/javascript/telefonia_shell.js","pairs":[["        confirmFinishedCall: function (ref, facts, token) {\n            if(currentState?.active||!facts?.finalizada||facts.pbx_call_id!==ref||String(facts.extension)!==String(cfg.extension))return false;\n            const receipts=Array.isArray(facts.sdk_tokens)?facts.sdk_tokens:[];\n            const match=s=>Boolean(s&&([s.pbxCallId,s.context?.transferReference,s.finalMetadata?.pbx_call_id].includes(ref)||(token&&s.callToken===token&&[s.callToken,s.context?.sdkSessionId].some(x=>receipts.includes(x)))));\n","        confirmFinishedCall: function (ref, facts, token) {\n            if(!facts?.finalizada||facts.pbx_call_id!==ref||String(facts.extension)!==String(cfg.extension))return false;\n            const receipts=Array.isArray(facts.sdk_tokens)?facts.sdk_tokens:[];\n            const match=s=>Boolean(s&&([s.pbxCallId,s.context?.transferReference,s.finalMetadata?.pbx_call_id].includes(ref)||(token&&s.callToken===token&&[s.callToken,s.context?.sdkSessionId].some(x=>receipts.includes(x)))));\n            if(currentState?.active){\n                // La evidencia PBX propia puede llegar mientras el host sigue\n                // en recuperación SIP. Cerrar únicamente el mismo token y\n                // referencia; nunca una conversación más reciente.\n                const ownRef=String(currentState.pbxCallId||currentState.context?.transferReference||'');\n                if(currentState.direction!=='incoming'||\n                    !['recovering','finishing'].includes(currentState.phase)||\n                    ownRef!==ref||!match(currentState))return false;\n                sendPhoneCommand('PBX_CONFIRMED_INCOMING_END',{\n                    callToken:currentState.callToken,ref,\n                    connected:Boolean(facts.conectada),seconds:facts.segundos\n                });\n                return true;\n            }\n"]]},{"path":"public/javascript/telefonia_ventas_shell.js","pairs":[["  if(facts.finalizada&&!state.active){","  if(facts.finalizada){"]]}]''')
HOME = Path.home()
LIVE = Path(os.environ.get("IMPE_TELEFONIA_SIP_PUBLIC", str(HOME / "domains/fundacionrededumex.org/public_html")))
BACKUPS = Path(os.environ.get("IMPE_TELEFONIA_SIP_BACKUPS", str(HOME / "respaldos-crm")))
MODE = sys.argv[1] if len(sys.argv) > 1 else "--check"
if MODE not in ("--check", "--apply", "--restore") or len(sys.argv) != (3 if MODE == "--restore" else (2 if len(sys.argv)>1 else 1)):
    raise SystemExit("Uso: --check | --apply | --restore RUTA_RESPALDO")

def fail(msg):
    raise RuntimeError(msg)
def digest(raw):
    return hashlib.sha256(raw).hexdigest()
def safe_parts(path):
    for part in (path, *path.parents):
        if part.is_symlink():
            fail("Ruta simbólica no autorizada: " + str(part))
def atomic(path, raw, mode):
    fd, temp = tempfile.mkstemp(prefix=".impe-stage-", dir=str(path.parent))
    try:
        os.fchmod(fd, mode)
        with os.fdopen(fd,"wb") as f:
            f.write(raw); f.flush(); os.fsync(f.fileno())
        os.replace(temp,path)
    finally:
        if os.path.exists(temp): os.unlink(temp)
# Ventas versión anterior publicada: actualización permitida ÚNICAMENTE
# si coincide el contenido completo normalizado, sin personalizaciones.
LEGACY_SALES_3EC = json.loads(r'''"/* Observador comercial de la capa compartida. No ejecuta SIP, DTMF ni transferencias. */\n(()=>{'use strict';\n const cfg=window.IMPE_PHONE_SHELL_CONFIG||{},phone=window.IMPE_TELEPHONY_SHELL;\n if(!cfg.salesResults||!phone||window.IMPE_SALES_ATTENTION)return;\n const crm=document.getElementById('impeCrmFrame'),panel=document.querySelector('[data-incoming-panel]');\n const key='impe:sales-pending:'+cfg.userId,valid=ref=>/^[A-Za-z0-9_-]{6,100}$/.test(String(ref||''));\n let pending=[],previous=null,scope='',revision=0,fetching=false,metadata=null;\n let acceptedBound=null,candidate=null,bound=null;\n const pbx=ref=>valid(ref)&&!/^sdk-/i.test(ref);\n const reference=state=>bound&&[bound.token,bound.token+'-external'].includes(String(state?.callToken))?bound.ref:String(state?.context?.transferReference||state?.finalMetadata?.pbx_call_id||state?.pbxCallId||'');\n const digits10=value=>String(value||'').replace(/\\D/g,'').slice(-10);\n // El nombre aceptado pertenece a una solicitud única, no al último\n // número marcado. Se ata una sola vez a la nueva invitación SDK o a su\n // referencia PBX exacta y no se hereda en llamadas consecutivas.\n const acceptedFor= (state,ref)=>{\n  const op=window.IMPE_AVAILABILITY?.acceptedTransfer?.();\n  if(!op?.nombre||!valid(op.referencia)||!state?.active||state.direction!=='incoming')return null;\n  const token=String(state.callToken||''),started=Number(state.requestedAt||0)*1000;\n  if(!token||!started||started<op.at-20000||started>op.at+90000)return null;\n  if(pbx(ref)&&ref!==op.referencia)return null;\n  if(!pbx(ref)&&state.context?.type!=='SDK_INCOMING')return null;\n  const caller=digits10(state.destination),expected=digits10(op.numero);\n  if(caller.length===10&&expected.length===10&&caller!==expected)return null;\n  if(acceptedBound&&acceptedBound.id!==op.id)acceptedBound=null;\n  if(acceptedBound&&!pbx(ref)&&acceptedBound.token!==token)return null;\n  if(!acceptedBound)acceptedBound={id:op.id,token};\n  return op;\n };\n try{const data=JSON.parse(localStorage.getItem(key)||sessionStorage.getItem(key)||'[]');if(Array.isArray(data))pending=data.filter(x=>pbx(x.ref)&&Date.now()-x.at<7*86400000).slice(-40);}catch(_){}\n const persist=()=>{try{localStorage.setItem(key,JSON.stringify(pending.slice(-40)));sessionStorage.removeItem(key);}catch(_){}};\n const card=document.createElement('div');card.className='impe-availability-source';card.hidden=true;\n const name=document.createElement('strong'),hint=document.createElement('p');card.append(name,hint);\n panel.querySelector('.persistent-phone-origin').after(card);\n const button=panel.querySelector('[data-incoming-register-finished]')||document.createElement('button');button.type='button';button.className='persistent-phone-action is-primary';button.textContent='Registrar resultado';button.dataset.incomingRegisterFinished='';button.hidden=true;\n const actions=panel.querySelector('.persistent-phone-actions'),close=actions?.querySelector('[data-incoming-close-finished]');\n if(close)actions.insertBefore(button,close);else actions?.append(button);\n // Los pendientes se notifican desde la campana y los toasts compartidos del CRM.\n const announced=new Set();\n const refreshNotices=()=>{try{const win=crm?.contentWindow;win?.dispatchEvent(new win.Event('impe:phone-notices-refresh'));}catch(_){/* La consulta periódica sigue disponible tras navegar. */}};\n const remove=ref=>{const existed=pending.some(p=>p.ref===ref);phone.dismissFinishedCall?.(ref);pending=pending.filter(p=>p.ref!==ref);persist();render();if(existed)refreshNotices();};\n const open=()=>{if(phone.getPhoneStatus().active||!crm)return;\n  const state=phone.getCallState(),ref=reference(state);\n  const task=state?.phase==='incoming-finished'||state?.phase==='finished'?pending.find(x=>x.ref===ref):pending[pending.length-1];\n  const url=new URL(cfg.salesHistoryUrl,location.href);\n  // Sin referencia PBX confirmada sólo puede consultarse el historial.\n  if(!task)return;\n  if(!metadata?.finalizada||metadata.pbx_call_id!==task.ref){void lookup(task.ref,revision);return;}\n  url.searchParams.set('pbx_call_id',task.ref);if(task.extension)url.searchParams.set('extension',task.extension);\n  if(crm.src===url.href){crm.contentWindow?.postMessage({type:'IMPE_SALES_RESULT_OPEN',ref:task.ref,extension:task.extension},location.origin);return;}\n  crm.src=url.href;\n };\n button.addEventListener('click',open);\n const render=()=>{const state=phone.getCallState(),active=Boolean(state?.active),form=phone.isResultFormOpen();\n  const ref=reference(state);\n  const finished=!active&&['finished','incoming-finished'].includes(state?.phase);\n  const task=finished?pending.find(x=>x.ref===ref):pending[pending.length-1];\n  const confirmed=Boolean(task&&metadata?.pbx_call_id===task.ref&&metadata.finalizada);\n  button.hidden=active||form||(!finished&&!pending.length);\n  button.disabled=!confirmed;\n  button.textContent=confirmed?'Registrar resultado':'Conciliando llamada';\n  const icon=document.createElement('i');icon.className='bi bi-journal-check';icon.setAttribute?.('aria-hidden','true');button.prepend?.(icon);\n  button.dataset.salesPending=task?'1':'0';\n };\n window.IMPE_SALES_ATTENTION=Object.freeze({hasPending:()=>pending.length>0,openPending:open});\n async function lookup(ref,version){\n  if(fetching||(!pbx(ref)&&!candidate))return;fetching=true;const requestToken=phone.getCallState()?.callToken;\n  const controller=typeof AbortController==='function'?new AbortController():null;\n  const timeout=controller?setTimeout(()=>controller.abort(),10000):null;\n  try{const url=new URL(cfg.salesDetailUrl,location.href);\n   if(pbx(ref))url.searchParams.set('pbx_call_id',ref);\n   else {url.searchParams.set('solicitud_id',candidate.id);url.searchParams.set('sdk',candidate.token);}\n   if(cfg.extension)url.searchParams.set('extension',cfg.extension);\n   const r=await fetch(url,{credentials:'same-origin',cache:'no-store',...(controller?{signal:controller.signal}:{})}),j=await r.json();\n   const current=phone.getCallState();\n   const currentRef=reference(current);\n   if(version!==revision||!r.ok||!j.ok||current?.callToken!==requestToken||(pbx(ref)&&current?.active&&currentRef!==ref))return;\n   metadata=j.llamada;if(!metadata)return;\n   ref=metadata.pbx_call_id;\n   if(!pbx(ref)||String(metadata.extension)!==String(cfg.extension))return;\n   if(candidate&&candidate.token===requestToken)bound={token:requestToken,ref};\n   if(metadata.finalizada&&!current?.active){\n    if(!metadata.registro&&!pending.some(p=>p.ref===ref)){pending.push({ref,extension:cfg.extension,at:Date.now(),callToken:requestToken});persist();}\n    phone.confirmFinishedCall?.(ref,metadata,requestToken);\n   }\n   if(metadata.registro)remove(ref);\n   else if(metadata.finalizada&&!phone.getPhoneStatus().active&&!announced.has(ref)){announced.add(ref);refreshNotices();crm?.contentWindow?.postMessage({type:'IMPE_PHONE_CALL_CHANGED'},location.origin);}\n   card.hidden=!current?.active||!metadata.nombre;name.textContent=metadata.nombre||'';\n   hint.textContent=metadata.origen_extension?'Nombre informado por Recepción · Ext. '+metadata.origen_extension:'Atención comercial de tu extensión';\n  }catch(_){/* El historial conserva el pendiente mientras llega evidencia PBX. */}finally{if(timeout!==null)clearTimeout(timeout);fetching=false;render();}\n }\n let lastLookup=0,tries=0,lastPending=0,pendingBusy=false;\n async function recoverPending(){\n  if(pendingBusy||Date.now()-lastPending<10000)return;pendingBusy=true;lastPending=Date.now();\n  try{const url=new URL(cfg.salesDetailUrl,location.href);url.searchParams.set('pendientes','1');\n   const response=await fetch(url,{credentials:'same-origin',cache:'no-store'}),data=await response.json();\n   if(response.ok&&data.ok&&Array.isArray(data.pendientes)){\n    for(const c of data.pendientes)if(valid(c.pbx_call_id)&&String(c.extension)===String(cfg.extension)&&!pending.some(p=>p.ref===c.pbx_call_id))pending.push({ref:c.pbx_call_id,extension:c.extension,at:Date.now()});\n    persist();\n   }\n  }catch(_){}finally{pendingBusy=false;}\n }\n void recoverPending();\n function observe(){\n  const state=phone.getCallState();if(!state){if(pending[0]&&Date.now()-lastLookup>Math.min(60000,3000*2**tries)){lastLookup=Date.now();tries++;void lookup(pending[0].ref,revision);}render();return;}\n  const ref=reference(state);\n  const identity=String(state.callToken||'')+':'+ref;\n  if(state.active&&identity!==scope){if(previous?.callToken!==state.callToken&&!String(state.callToken).endsWith('-external')){candidate=null;bound=null;}scope=identity;++revision;metadata=null;card.hidden=true;name.textContent='';hint.textContent='';lastLookup=0;tries=0;}\n  if(state.active&&!metadata){\n   const accepted=acceptedFor(state,ref);\n   if(accepted){\n    candidate={id:Number(accepted.id),token:state.callToken};\n    name.textContent=accepted.nombre;\n    hint.textContent='Nombre informado en disponibilidad · Ext. '+accepted.origen_extension;\n    card.hidden=false;phone.bindTransferCandidate?.(Number(accepted.id));\n   }\n  }\n  if(previous?.active&&!state.active&&pbx(ref)&&['finished','incoming-finished'].includes(state.phase)){\n   metadata=null;lastLookup=0;tries=0;\n   // También una llamada no contestada necesita poder consultar su resultado.\n   // La referencia PBX y la finalización se validan en el servidor.\n   const needs=previous.direction==='outgoing'||previous.direction==='incoming';\n   if(needs&&!pending.some(p=>p.ref===ref)){\n    pending.push({ref,extension:cfg.extension,at:Date.now(),callToken:state.callToken||previous.callToken});persist();\n    crm?.contentWindow?.postMessage({type:'IMPE_PHONE_CALL_CHANGED'},location.origin);\n    // El resultado se abre sólo cuando el usuario lo solicita; evita dos modales superpuestos.\n   }\n  }\n  const currentRef=pbx(ref)?ref:candidate?ref:state.active?null:pending[0]?.ref;\n  if(!state.active){card.hidden=true;void recoverPending();}\n  if((currentRef||candidate)&&Date.now()-lastLookup>(metadata?.finalizada?60000:Math.min(10000,1500*2**Math.min(tries,3)))&&(!metadata||!state.active)){\n   lastLookup=Date.now();tries++;void lookup(currentRef,revision);\n  }\n  previous=state;render();\n }\n window.addEventListener('message',e=>{\n  if(e.origin!==location.origin||e.source!==crm?.contentWindow||e.data?.type!=='IMPE_SALES_RESULT_SAVED'||!valid(e.data.ref))return;\n  // El mensaje sólo actualiza UX; toda consulta/guardado se autoriza en servidor.\n  const task=pending.find(p=>p.ref===e.data.ref&&p.extension===e.data.extension);remove(e.data.ref);\n  if(task&&!phone.getPhoneStatus().active){try{localStorage.setItem('impe:telephony:command:'+cfg.userId,JSON.stringify({type:'COMMAND',action:'CLEAR_FINISHED',payload:{callToken:task.callToken},id:'sales-clear-'+Date.now()}));}catch(_){}}\n });\n setInterval(observe,750);observe();\n})();\n"''')
TARGET_SALES_CURRENT = json.loads(r'''"/* Observador comercial de la capa compartida. No ejecuta SIP, DTMF ni transferencias. */\n(()=>{'use strict';\n const cfg=window.IMPE_PHONE_SHELL_CONFIG||{},phone=window.IMPE_TELEPHONY_SHELL;\n if(!cfg.salesResults||!phone||window.IMPE_SALES_ATTENTION)return;\n const crm=document.getElementById('impeCrmFrame'),panel=document.querySelector('[data-incoming-panel]');\n const key='impe:sales-pending:'+cfg.userId,valid=ref=>/^[A-Za-z0-9_-]{6,100}$/.test(String(ref||''));\n let pending=[],previous=null,scope='',revision=0,fetching=false,metadata=null;\n let acceptedBound=null,candidate=null,bound=null;\n const pbx=ref=>valid(ref)&&!/^sdk-/i.test(ref);\n const identities=state=>[state?.callToken,state?.context?.sdkSessionId].filter(x=>typeof x==='string'&&x);\n const overlaps=(a,b)=>a.some(x=>b.includes(x));\n const reference=state=>{\n  if(bound&&overlaps(bound.tokens,identities(state)))return bound.ref;\n  // Una referencia local SDK no debe ocultar una PBX final posterior.\n  const refs=Array.from(new Set([state?.context?.transferReference,state?.finalMetadata?.pbx_call_id,state?.pbxCallId].filter(pbx)));\n  return refs.length===1?refs[0]:'';\n };\n const digits10=value=>String(value||'').replace(/\\D/g,'').slice(-10);\n // El nombre aceptado pertenece a una solicitud única, no al último\n // número marcado. Se ata una sola vez a la nueva invitación SDK o a su\n // referencia PBX exacta y no se hereda en llamadas consecutivas.\n const acceptedFor= (state,ref)=>{\n  const op=window.IMPE_AVAILABILITY?.acceptedTransfer?.();\n  if(!op?.nombre||!valid(op.referencia)||!state?.active||state.direction!=='incoming')return null;\n  const token=String(state.callToken||''),started=Number(state.requestedAt||0)*1000;\n  if(!token||!started||started<op.at-20000||started>op.at+90000)return null;\n  if(pbx(ref)&&ref!==op.referencia)return null;\n  if(!pbx(ref)&&state.context?.type!=='SDK_INCOMING')return null;\n  const caller=digits10(state.destination),expected=digits10(op.numero);\n  if(caller.length===10&&expected.length===10&&caller!==expected)return null;\n  if(acceptedBound&&acceptedBound.id!==op.id)acceptedBound=null;\n  if(acceptedBound&&!pbx(ref)&&acceptedBound.token!==token)return null;\n  if(!acceptedBound)acceptedBound={id:op.id,token};\n  return op;\n };\n try{const data=JSON.parse(localStorage.getItem(key)||sessionStorage.getItem(key)||'[]');if(Array.isArray(data))pending=data.filter(x=>pbx(x.ref)&&Date.now()-x.at<7*86400000).slice(-40);}catch(_){}\n const persist=()=>{try{localStorage.setItem(key,JSON.stringify(pending.slice(-40)));sessionStorage.removeItem(key);}catch(_){}};\n const card=document.createElement('div');card.className='impe-availability-source';card.hidden=true;\n const name=document.createElement('strong'),hint=document.createElement('p');card.append(name,hint);\n panel.querySelector('.persistent-phone-origin').after(card);\n const button=panel.querySelector('[data-incoming-register-finished]')||document.createElement('button');button.type='button';button.className='persistent-phone-action is-primary';button.textContent='Registrar resultado';button.dataset.incomingRegisterFinished='';button.hidden=true;\n const actions=panel.querySelector('.persistent-phone-actions'),close=actions?.querySelector('[data-incoming-close-finished]');\n if(close)actions.insertBefore(button,close);else actions?.append(button);\n // Los pendientes se notifican desde la campana y los toasts compartidos del CRM.\n const announced=new Set(),retired=new Set();\n const refreshNotices=()=>{try{const win=crm?.contentWindow;win?.dispatchEvent(new win.Event('impe:phone-notices-refresh'));}catch(_){/* La consulta periódica sigue disponible tras navegar. */}};\n const remove=ref=>{retired.add(ref);const existed=pending.some(p=>p.ref===ref);phone.dismissFinishedCall?.(ref);pending=pending.filter(p=>p.ref!==ref);persist();render();if(existed)refreshNotices();};\n const open=()=>{if(phone.getPhoneStatus().active||!crm)return;\n  const state=phone.getCallState(),ref=reference(state);\n  const task=state?.phase==='incoming-finished'||state?.phase==='finished'?pending.find(x=>x.ref===ref):pending[0];\n  const url=new URL(cfg.salesHistoryUrl,location.href);\n  // Sin referencia PBX confirmada sólo puede consultarse el historial.\n  if(!task)return;\n  if(!metadata?.finalizada||metadata.pbx_call_id!==task.ref){void lookup(task.ref,revision);return;}\n  url.searchParams.set('pbx_call_id',task.ref);if(task.extension)url.searchParams.set('extension',task.extension);\n  if(crm.src===url.href){crm.contentWindow?.postMessage({type:'IMPE_SALES_RESULT_OPEN',ref:task.ref,extension:task.extension},location.origin);return;}\n  crm.src=url.href;\n };\n button.addEventListener('click',open);\n const render=()=>{const state=phone.getCallState(),active=Boolean(state?.active),form=phone.isResultFormOpen();\n  const ref=reference(state);\n  const finished=!active&&['finished','incoming-finished'].includes(state?.phase);\n  const task=finished?pending.find(x=>x.ref===ref):pending[0];\n  const confirmed=Boolean(task&&metadata?.pbx_call_id===task.ref&&metadata.finalizada);\n  button.hidden=active||form||(!finished&&!pending.length);\n  button.disabled=!confirmed;\n  button.textContent=confirmed?'Registrar resultado':'Conciliando llamada';\n  const icon=document.createElement('i');icon.className='bi bi-journal-check';icon.setAttribute?.('aria-hidden','true');button.prepend?.(icon);\n  button.dataset.salesPending=task?'1':'0';\n };\n window.IMPE_SALES_ATTENTION=Object.freeze({hasPending:()=>pending.length>0,openPending:open});\n function adopt(facts,state,expectedRef=''){\n  if(!facts||!state||!pbx(facts.pbx_call_id)||String(facts.extension)!==String(cfg.extension)||retired.has(facts.pbx_call_id))return false;\n  const ref=facts.pbx_call_id,keys=identities(state);\n  if(keys.some(x=>/^sdk-/.test(x))&&facts.sdk_tokens?.length&&!overlaps(keys,facts.sdk_tokens))return false;\n  if(expectedRef?ref!==expectedRef:!overlaps(keys,Array.isArray(facts.sdk_tokens)?facts.sdk_tokens:[]))return false;\n  if(metadata?.pbx_call_id===ref&&metadata.finalizada&&!facts.finalizada)return false;\n  metadata=facts;bound={ref,tokens:keys};\n  if(facts.finalizada){\n   if(facts.registro){remove(ref);return true;}\n   const task=pending.find(p=>p.ref===ref);\n   if(task)task.callToken=state.callToken;\n   else pending.push({ref,extension:cfg.extension,at:Date.now(),callToken:state.callToken});\n   persist();\n   phone.confirmFinishedCall?.(ref,facts,state.callToken);\n   if(!announced.has(ref)){announced.add(ref);refreshNotices();crm?.contentWindow?.postMessage({type:'IMPE_PHONE_CALL_CHANGED'},location.origin);}\n  }\n  render();return true;\n }\n async function lookup(ref,version){\n  const initial=phone.getCallState(),sdk=identities(initial).find(x=>/^sdk-/.test(x));\n  if(fetching||(!pbx(ref)&&!candidate&&!sdk))return;fetching=true;const requestKeys=identities(initial);\n  const controller=typeof AbortController==='function'?new AbortController():null;\n  const timeout=controller?setTimeout(()=>controller.abort(),10000):null;\n  try{const url=new URL(cfg.salesDetailUrl,location.href);\n   if(pbx(ref))url.searchParams.set('pbx_call_id',ref);\n   else if(candidate){url.searchParams.set('solicitud_id',candidate.id);url.searchParams.set('sdk',candidate.token);}\n   else url.searchParams.set('sdk',sdk);\n   if(cfg.extension)url.searchParams.set('extension',cfg.extension);\n   const r=await fetch(url,{credentials:'same-origin',cache:'no-store',...(controller?{signal:controller.signal}:{})}),j=await r.json();\n   const current=phone.getCallState();\n   const currentRef=reference(current);\n   const idle=s=>!s||(!s.active&&['ready','idle'].includes(s.phase)&&identities(s).length===0);\n   if(idle(initial)&&idle(current)&&version===revision&&r.ok&&j.ok&&pbx(ref)&&pending.some(p=>p.ref===ref)){\n    const facts=j.llamada;\n    if(facts?.pbx_call_id===ref&&String(facts.extension)===String(cfg.extension)&&!retired.has(ref)){\n     metadata=facts;if(facts.registro)remove(ref);\n    }\n    return;\n   }\n   if(version!==revision||!r.ok||!j.ok||!overlaps(identities(current),requestKeys)||(pbx(ref)&&current?.active&&currentRef!==ref))return;\n   if(!adopt(j.llamada,current,pbx(ref)?ref:''))return;\n   card.hidden=!current?.active||!metadata.nombre;name.textContent=metadata.nombre||'';\n   hint.textContent=metadata.origen_extension?'Nombre informado por Recepción · Ext. '+metadata.origen_extension:'Atención comercial de tu extensión';\n  }catch(_){/* El historial conserva el pendiente mientras llega evidencia PBX. */}finally{if(timeout!==null)clearTimeout(timeout);fetching=false;render();}\n }\n let lastLookup=0,tries=0,lastPending=0,pendingBusy=false;\n async function recoverPending(){\n  if(pendingBusy||Date.now()-lastPending<10000)return;pendingBusy=true;lastPending=Date.now();\n  const version=revision,requestKeys=identities(phone.getCallState());\n  try{const url=new URL(cfg.salesDetailUrl,location.href);url.searchParams.set('pendientes','1');\n   const response=await fetch(url,{credentials:'same-origin',cache:'no-store'}),data=await response.json();\n   if(response.ok&&data.ok&&Array.isArray(data.pendientes)){\n    const rows=data.pendientes.filter(c=>pbx(c.pbx_call_id)&&c.finalizada===true&&String(c.extension)===String(cfg.extension)&&!retired.has(c.pbx_call_id));\n    for(const c of rows)if(!pending.some(p=>p.ref===c.pbx_call_id))pending.push({ref:c.pbx_call_id,extension:c.extension,at:Date.now()});\n    persist();\n    const current=phone.getCallState(),ref=reference(current);\n    if(version===revision&&current&&!current.active&&overlaps(identities(current),requestKeys)){\n     const matches=rows.filter(c=>pbx(ref)?c.pbx_call_id===ref:overlaps(identities(current),c.sdk_tokens||[]));\n     if(matches.length===1)adopt(matches[0],current,pbx(ref)?ref:'');\n    }\n   }\n  }catch(_){}finally{pendingBusy=false;render();}\n }\n void recoverPending();\n function observe(){\n  const state=phone.getCallState();if(!state){if(pending[0]&&Date.now()-lastLookup>Math.min(60000,3000*2**tries)){lastLookup=Date.now();tries++;void lookup(pending[0].ref,revision);}render();return;}\n  let ref=reference(state);\n  const identity=String(state.callToken||'')+':'+ref;\n  if(state.active&&identity!==scope){\n   const nextCall=previous&&(!previous.active||!overlaps(identities(previous),identities(state)));\n   if(nextCall){candidate=null;bound=null;ref=reference(state);}\n   if(nextCall||!scope||(pbx(ref)&&metadata&&metadata.pbx_call_id!==ref)){++revision;metadata=null;card.hidden=true;name.textContent='';hint.textContent='';}\n   scope=String(state.callToken||'')+':'+ref;lastLookup=0;tries=0;\n  }\n  if(state.active&&!metadata){\n   const accepted=acceptedFor(state,ref);\n   if(accepted){\n    candidate={id:Number(accepted.id),token:state.callToken};\n    name.textContent=accepted.nombre;\n    hint.textContent='Nombre informado en disponibilidad · Ext. '+accepted.origen_extension;\n    card.hidden=false;phone.bindTransferCandidate?.(Number(accepted.id));\n   }\n  }\n  if(previous?.active&&!state.active&&pbx(ref)&&['finished','incoming-finished'].includes(state.phase)){\n   if(metadata?.pbx_call_id===ref&&metadata.finalizada)adopt(metadata,state,ref);\n   else metadata=null;\n   lastLookup=0;tries=0;\n   // También una llamada no contestada necesita poder consultar su resultado.\n   // La referencia PBX y la finalización se validan en el servidor.\n   const needs=previous.direction==='outgoing'||previous.direction==='incoming';\n   if(needs&&!pending.some(p=>p.ref===ref)){\n    pending.push({ref,extension:cfg.extension,at:Date.now(),callToken:state.callToken||previous.callToken});persist();\n    crm?.contentWindow?.postMessage({type:'IMPE_PHONE_CALL_CHANGED'},location.origin);\n    // El resultado se abre sólo cuando el usuario lo solicita; evita dos modales superpuestos.\n   }\n  }\n  const currentRef=pbx(ref)?ref:'';\n  if(!state.active){card.hidden=true;void recoverPending();}\n  if((currentRef||candidate||identities(state).some(x=>/^sdk-/.test(x)))&&Date.now()-lastLookup>(metadata?.finalizada?60000:Math.min(10000,1500*2**Math.min(tries,3)))&&(!metadata||!state.active)){\n   lastLookup=Date.now();tries++;void lookup(currentRef,revision);\n  }\n  previous=state;render();\n }\n window.addEventListener('message',e=>{\n  if(e.origin!==location.origin||e.source!==crm?.contentWindow||e.data?.type!=='IMPE_SALES_RESULT_SAVED'||!valid(e.data.ref))return;\n  // El mensaje sólo actualiza UX; toda consulta/guardado se autoriza en servidor.\n  const task=pending.find(p=>p.ref===e.data.ref&&p.extension===e.data.extension);remove(e.data.ref);\n  if(task&&!phone.getPhoneStatus().active){try{localStorage.setItem('impe:telephony:command:'+cfg.userId,JSON.stringify({type:'COMMAND',action:'CLEAR_FINISHED',payload:{callToken:task.callToken},id:'sales-clear-'+Date.now()}));}catch(_){}}\n });\n setInterval(observe,750);observe();\n})();\n"''')

def patch(raw, pairs, filename):
    crlf = b"\r\n" in raw
    if crlf and raw.count(b"\n") != raw.count(b"\r\n"):
        fail("Finales de línea mixtos: " + filename)
    content = raw.decode("utf-8").replace("\r\n","\n")
    if filename == "public/css/telefonia_shell.css":
        # El CSS del CRM en Hostinger tiene personalizaciones legítimas.
        # El cambio de esta entrega SOLO agrega reglas; nunca reemplazar
        # reglas existentes ni depender de su fragmento final.
        if len(pairs) != 1 or not pairs[0][1].startswith(pairs[0][0]):
            fail("CONFLICTO: CSS no es un cambio exclusivamente aditivo")
        addon = pairs[0][1][len(pairs[0][0]):]
        marker = "/* Ventas: al cerrar sin resultado"
        if not addon.startswith("\n" + marker):
            fail("CONFLICTO: bloque CSS de esta entrega no reconocido")
        if addon.strip() in content:
            return raw  # Ya aplicado, incluso si existen más personalizaciones.
        if marker in content:
            fail("CONFLICTO: el bloque CSS existe pero está incompleto o editado")
        content += ("\n" if content and not content.endswith("\n") else "") + addon
        return content.replace("\n","\r\n").encode("utf-8") if crlf else content.encode("utf-8")
    if filename == "public/javascript/telefonia_ventas_shell.js" and len(pairs) == 1:
        original, upgraded = pairs[0]
        if content.count(upgraded) == 1 and "function adopt(" in content and "sdk_tokens" in content:
            return raw
        if content.count(original) == 1 and "function adopt(" in content and "sdk_tokens" in content:
            content = content.replace(original, upgraded, 1)
            return content.replace("\n","\r\n").encode("utf-8") if crlf else content.encode("utf-8")
        if content == LEGACY_SALES_3EC:
            # Actualiza una versión antigua EXACTA; nunca pisa un ajuste local.
            content = TARGET_SALES_CURRENT
            if "if(facts.finalizada){" not in content or "sdk_tokens" not in content:
                fail("CONFLICTO: versión destino de Ventas no verificada")
            return content.replace("\n","\r\n").encode("utf-8") if crlf else content.encode("utf-8")
        fail("CONFLICTO: Ventas personalizada o variante desconocida (SHA256: " +
             digest(raw) + "). No se reemplaza.")
    if filename == "public/javascript/telefonia_shell.js" and len(pairs) == 1:
        old,new = pairs[0]
        if content.count(new) == 1:
            return raw
        if content.count(old) != 1:
            # Si esta misma entrega ya está instalada, --check debe
            # ser idempotente sin reescribir una versión personalizada.
            signature = "confirmFinishedCall: function (ref, facts, token) {"
            finish = "dismissFinishedCall: function (ref) {"
            if content.count(signature) == 1 and content.count(finish) == 1:
                test_method = content[content.index(signature):content.index(finish,content.index(signature))]
                expected = (
                    "if(!facts?.finalizada||facts.pbx_call_id!==ref||String(facts.extension)!==String(cfg.extension))return false;",
                    "const receipts=Array.isArray(facts.sdk_tokens)?facts.sdk_tokens:[];",
                    "ownRef!==ref||!match(currentState)",
                    "sendPhoneCommand('PBX_CONFIRMED_INCOMING_END'",
                    "if(changed)render();return changed;"
                )
                if all(test_method.count(v) == 1 for v in expected):
                    return raw
            # Se obtuvo SHA256 exacto del shell personalizado en Hostinger.
            # Nada de sustituir el JS completo o aflojar validaciones para
            # versiones que no sean esa misma. En tal caso abortar sin escritura.
            pinned = "45519cd6d21ea17071edf41f2343772ffb7e5e2a43c76bc3b5389e4aead1ec79"
            if digest(raw) != pinned:
                fail("CONFLICTO: shell distinto al SHA256 proporcionado; abortar sin cambios")
            start_sig = "confirmFinishedCall: function (ref, facts, token) {"
            end_sig = "dismissFinishedCall: function (ref) {"
            if content.count(start_sig) != 1 or content.count(end_sig) != 1:
                fail("CONFLICTO: límites confirmFinishedCall no únicos")
            left=content.index(start_sig)
            right=content.index(end_sig,left)
            method=content[left:right]
            # Verificar TODOS los fragmentos originales observados.
            original_guard = "if(currentState?.active||!facts?.finalizada||String(facts.extension)!==String(cfg.extension))return false;"
            original_match = "const match=s=>[s?.pbxCallId,s?.context?.transferReference,s?.finalMetadata?.pbx_call_id].includes(ref)||(token&&s?.callToken===token&&currentState?.callToken===token);"
            original_update = "const update=s=>({...s,pbxCallId:ref,status:'completed'"
            original_incoming = "if(recentFinishedIncoming&&match(recentFinishedIncoming.state))recentFinishedIncoming.state=update(recentFinishedIncoming.state);"
            original_outgoing = "if(recentFinishedOutgoing&&match(recentFinishedOutgoing.state))recentFinishedOutgoing.state=update(recentFinishedOutgoing.state);"
            original_tail = "render();return true;"
            for fragment in (original_guard,original_match,original_update,original_incoming,original_outgoing,original_tail):
                if method.count(fragment) != 1:
                    fail("CONFLICTO: función de Hostinger distinta a la analizada")
            new_guard = "if(!facts?.finalizada||facts.pbx_call_id!==ref||String(facts.extension)!==String(cfg.extension))return false;"
            new_match = (
                "const receipts=Array.isArray(facts.sdk_tokens)?facts.sdk_tokens:[];\n"
                "            const match=s=>Boolean(s&&([s.pbxCallId,s.context?.transferReference,s.finalMetadata?.pbx_call_id].includes(ref)||(token&&s.callToken===token&&[s.callToken,s.context?.sdkSessionId].some(x=>receipts.includes(x)))));"
            )
            # Rama que proviene de la implementación actual del PR, no de
            # un texto improvisado sobre la función privada de Hostinger.
            branch_begin = "            if(currentState?.active){"
            if new.count(branch_begin) != 1:
                fail("CONFLICTO: falta rama PBX verificada del PR")
            active_branch = new[new.index(branch_begin):]
            if not active_branch.endswith("return true;\n            }\n"):
                fail("CONFLICTO: rama PBX no está completa")
            if not all(t in active_branch for t in
                    ("ownRef!==ref","!match(currentState)","PBX_CONFIRMED_INCOMING_END","callToken:currentState.callToken")):
                fail("CONFLICTO: rama PBX no cumple guardas de sesión")
            method = method.replace(original_guard,new_guard,1)
            method = method.replace(original_match,new_match,1)
            method = method.replace("            "+original_update,active_branch+"            "+original_update,1)
            # La versión antigua decía éxito sin coincidencia; mantener
            # coincidencia exacta antes de actualizar/habilitar resultado.
            method = method.replace(original_incoming,
                "let changed=false;\n"
                "            if(recentFinishedIncoming&&match(recentFinishedIncoming.state)){recentFinishedIncoming.state=update(recentFinishedIncoming.state);changed=true;}",1)
            method = method.replace(original_outgoing,
                "if(recentFinishedOutgoing&&match(recentFinishedOutgoing.state)){recentFinishedOutgoing.state=update(recentFinishedOutgoing.state);changed=true;}",1)
            method = method.replace(original_tail,
                "if(match(currentState)){currentState=update(currentState);changed=true;}\n"
                "            if(changed)render();return changed;",1)
            content = content[:left]+method+content[right:]
            if content.count("PBX_CONFIRMED_INCOMING_END") != 1:
                fail("CONFLICTO: rama PBX insertada de manera ambigua")
            return content.replace("\n","\r\n").encode("utf-8") if crlf else content.encode("utf-8")
    for old,new in pairs:
        if content.count(new) == 1:
            continue
        if content.count(old) != 1:
            fail("CONFLICTO: fragmento desconocido o repetido: " + filename)
        content = content.replace(old,new,1)
    return content.replace("\n","\r\n").encode("utf-8") if crlf else content.encode("utf-8")

safe_parts(LIVE)
LIVE = LIVE.resolve(strict=True)
if not (LIVE / "index.php").is_file(): fail("Ruta del CRM inválida")
safe_parts(BACKUPS)
BACKUPS = BACKUPS.resolve()
if LIVE == BACKUPS or LIVE in BACKUPS.parents or BACKUPS in LIVE.parents:
    fail("Los respaldos deben estar fuera del CRM")
files = {}
for entry in PATCHES:
    rel=entry["path"]
    if rel not in ("public/javascript/telefonia_sdk_entrantes_observador.js","public/javascript/telefonia_host.js","public/javascript/telefonia_shell.js","public/javascript/telefonia_ventas_shell.js"):
        fail("Archivo fuera de alcance")
    path=LIVE / rel
    safe_parts(path)
    if not path.is_file(): fail("Falta: "+rel)
    files[rel]=path
lock=LIVE.parent / ".impe-atencion-deploy.lock"
locked=False
if MODE != "--check":
    safe_parts(lock)
    try:
        os.mkdir(lock,0o700);locked=True
    except FileExistsError:
        fail("Otra publicación de CRM está en progreso: "+str(lock))
try:
    if MODE == "--restore":
        backup=Path(sys.argv[2])
        safe_parts(backup)
        backup=backup.resolve(strict=True)
        if BACKUPS not in backup.parents: fail("Respaldo fuera de carpeta autorizada")
        manifest=json.loads((backup/"manifest.json").read_text("utf-8"))
        if manifest.get("tag") != TAG: fail("Respaldo de otra entrega")
        rows=manifest["files"]
        if not rows: fail("Respaldo vacío")
        for rel,row in rows.items():
            if rel not in files: fail("Ruta no autorizada en manifiesto")
            if digest(files[rel].read_bytes()) != row["after"]:
                fail("Archivo editado después de publicar, no sobrescribir: "+rel)
            if digest((backup/rel).read_bytes()) != row["before"]:
                fail("Respaldo inconsistente: "+rel)
        for rel,row in rows.items():
            path=files[rel]
            atomic(path,(backup/rel).read_bytes(),int(row["mode"]))
            print("RESTAURADO:",rel)
        print("OK: restauración exacta, sin SQL")
    else:
        staged={}
        for entry in PATCHES:
            rel=entry["path"];path=files[rel]
            original=path.read_bytes()
            modified=patch(original,entry["pairs"],rel)
            if modified != original:
                staged[rel]=(original,modified,path.stat().st_mode & 0o777)
                print("PREPARADO:",rel)
            else:
                print("YA APLICADO:",rel)
        if MODE=="--check":
            print("OK: revisión compatible; sitio intacto. Archivos a modificar:",len(staged))
        elif not staged:
            print("OK: actualización aplicada anteriormente. Nada que hacer.")
        else:
            BACKUPS.mkdir(parents=True,exist_ok=True)
            backup=BACKUPS/(TAG+"-"+datetime.datetime.utcnow().strftime("%Y%m%d-%H%M%S")+"-"+str(os.getpid()))
            backup.mkdir(mode=0o700)
            manifest={"tag":TAG,"files":{}}
            for rel,(before,after,mode) in staged.items():
                target=backup/rel;target.parent.mkdir(parents=True,exist_ok=True)
                target.write_bytes(before)
                if digest(target.read_bytes())!=digest(before): fail("Respaldo incompleto")
                manifest["files"][rel]={"before":digest(before),"after":digest(after),"mode":mode}
            (backup/"manifest.json").write_text(json.dumps(manifest,indent=2),encoding="utf-8")
            # Comprobar ausencia de ediciones entre la revisión y la escritura.
            for rel,(before,after,mode) in staged.items():
                if files[rel].read_bytes()!=before: fail("El CRM cambió durante la revisión: "+rel)
            written=[]
            try:
                for rel,(before,after,mode) in staged.items():
                    atomic(files[rel],after,mode);written.append(rel)
                print("OK: aplicado",len(staged),"archivos. Sin SQL.")
                print("RESPALDO:",backup)
                print("ROLLBACK: bash",os.environ["IMPE_PUBLISHER_PATH"],"--restore",backup)
            except Exception:
                for rel in written:
                    before,after,mode=staged[rel]
                    if files[rel].read_bytes()==after: atomic(files[rel],before,mode)
                raise
finally:
    if locked:
        os.rmdir(lock)
PY
