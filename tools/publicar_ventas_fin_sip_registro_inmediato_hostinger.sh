#!/usr/bin/env bash
# Ventas: cierre SIP PUSH tardío y Registrar resultado inmediato. 4 JS.
# Compatible con Python 3.6 en Hostinger, sin SQL/estructura ni audio.
set -euo pipefail
umask 077
IMPE_PUBLISHER_PATH="$(realpath "$0")" python3 - "$@" <<'PY'
import hashlib, json, os, sys, tempfile, subprocess, stat, datetime, shutil
from pathlib import Path
TAG="impe-ventas-sip-registro-inmediato-20261021-v1"
PATCHES=json.loads(r'''[{"path":"public/javascript/telefonia_sdk_entrantes_observador.js","pairs":[["            session.on('ended', ended);\n            session.on('failed', ended);\n        };\n\n        const read = function () {\n            const input = doc.querySelector(","            session.on('ended', ended);\n            session.on('failed', ended);\n        };\n        const announceSession = function (session) {\n            if (seenIncoming && session?.direction === 'incoming' &&\n                !session.isEnded?.() && String(session.id || '')) {\n                hooks.onSessionBound?.({sessionId:String(session.id)});\n            }\n        };\n\n        const read = function () {\n            const input = doc.querySelector("],["                if (hooks.onRinging(call) === false) {\n                    // El host aún no puede publicarla: reintentar, no consumirla.\n                    seenIncoming=false;ringing=false;observedSession=null;\n                }\n                return;\n            }","                if (hooks.onRinging(call) === false) {\n                    // El host aún no puede publicarla: reintentar, no consumirla.\n                    seenIncoming=false;ringing=false;observedSession=null;\n                } else {\n                    // PUSH puede existir antes de que Zadarma cree la RTCSession.\n                    announceSession(observedSession);\n                }\n                return;\n            }"],["            if (session && !session.isEnded() && !observedSession) {\n                observedSession = session;\n                watchSession(observedSession);\n            }\n            // Evaluar el fin ANTES que la respuesta y antes del DOM: el SDK\n            // puede conservar incoming, ringing o el reloj después del cierre.","            if (session && !session.isEnded() && !observedSession) {\n                observedSession = session;\n                watchSession(observedSession);\n                announceSession(observedSession);\n            }\n            // Evaluar el fin ANTES que la respuesta y antes del DOM: el SDK\n            // puede conservar incoming, ringing o el reloj después del cierre."],["                acceptanceRequestedUntil = 0;\n                if (!connectedReported) {\n                    connectedReported = true;\n                    hooks.onConnected?.({sessionId:String(observedSession?.id||'')});\n                }\n                return;","                acceptanceRequestedUntil = 0;\n                if (!connectedReported) {\n                    connectedReported = true;\n                    // Algunos flujos ya pasaron a in-progress por PBX antes\n                    // de que JsSIP anunciara la sesión establecida.\n                    announceSession(observedSession);\n                    hooks.onConnected?.({sessionId:String(observedSession?.id||'')});\n                }\n                return;"]]},{"path":"public/javascript/telefonia_host.js","pairs":[["                        message: 'Timbrado detectado en el teléfono WebRTC. Registro PBX pendiente.'\n                    });\n                },\n                onConnected: function (evidence={}) {\n                    // La llamada transferida puede recibir su PBX ID ANTES\n                    // de que el SDK anuncie audio. La señal local sigue","                        message: 'Timbrado detectado en el teléfono WebRTC. Registro PBX pendiente.'\n                    });\n                },\n                onSessionBound: function (evidence={}) {\n                    // Una transferencia saliente de Recepción se observa\n                    // primero como PUSH y puede recibir la RTCSession cuando\n                    // ya está \"in-progress\" por NOTIFY_ANSWER de PBX.\n                    // Guardar el ID de la sesión actual permite que su END\n                    // cierre el modal al instante; no atribuirlo a otro INVITE.\n                    const id=String(evidence.sessionId||'');\n                    if(!id||!state.active||state.direction!=='incoming'||\n                        !['SDK_INCOMING','INCOMING'].includes(state.context?.type)||\n                        !['incoming-ringing','answering','in-progress','finishing','recovering'].includes(state.phase))return;\n                    const prior=String(state.context?.sdkInviteId||'');\n                    if(prior&&prior!==id)return;\n                    if(!prior)publish({context:{...state.context,sdkInviteId:id}});\n                },\n                onConnected: function (evidence={}) {\n                    // La llamada transferida puede recibir su PBX ID ANTES\n                    // de que el SDK anuncie audio. La señal local sigue"],["                    const observedId = String(evidence.sessionId || '');\n                    const boundId = String(state.context?.sdkInviteId || '');\n                    const sdkOnly = isSdkOnlyIncoming();\n                    const attachedPbx = state.context?.type === 'INCOMING' &&\n                        Boolean(String(state.pbxCallId || ''));\n                    const sameSip = Boolean(observedId && (\n                        (boundId && observedId === boundId) ||","                    const observedId = String(evidence.sessionId || '');\n                    const boundId = String(state.context?.sdkInviteId || '');\n                    const sdkOnly = isSdkOnlyIncoming();\n                    const attachedPbx = ['INCOMING','SDK_INCOMING'].includes(state.context?.type) &&\n                        Boolean(String(state.pbxCallId || ''));\n                    const sameSip = Boolean(observedId && (\n                        (boundId && observedId === boundId) ||"]]},{"path":"public/javascript/telefonia_ventas_shell.js","pairs":[["  return refs.length===1?refs[0]:'';\n };\n const digits10=value=>String(value||'').replace(/\\D/g,'').slice(-10);\n // El nombre aceptado pertenece a una solicitud única, no al último\n // número marcado. Se ata una sola vez a la nueva invitación SDK o a su\n // referencia PBX exacta y no se hereda en llamadas consecutivas.","  return refs.length===1?refs[0]:'';\n };\n const digits10=value=>String(value||'').replace(/\\D/g,'').slice(-10);\n // El SDK cerró esta conversación, pero los eventos PBX pueden llegar más\n // tarde. Permitir abrir YA el formulario de esa transferencia concreta;\n // el backend sigue siendo quien autoriza el guardado definitivo.\n const sipFinished=state=>Boolean(state&&!state.active&&\n  ['incoming-finished','finished'].includes(state.phase)&&\n  state.context?.sdkEnded===true&&\n  (state.context?.sdkConnected||state.context?.pbxAnswered||Number(state.answeredAtMs)>0));\n const provisional=state=>{\n  if(!sipFinished(state))return null;\n  const ref=reference(state);\n  if(pbx(ref))return {ref,extension:cfg.extension,callToken:state.callToken};\n  const sameCandidate=Boolean(candidate&&candidate.ref&&pbx(candidate.ref)&&\n   [state.callToken,state.context?.sdkSessionId,\n    String(state.callToken||'').replace(/-external$/,'')].includes(candidate.token));\n  return sameCandidate?{ref:candidate.ref,extension:cfg.extension,callToken:state.callToken}:null;\n };\n // El nombre aceptado pertenece a una solicitud única, no al último\n // número marcado. Se ata una sola vez a la nueva invitación SDK o a su\n // referencia PBX exacta y no se hereda en llamadas consecutivas."],[" const remove=(ref,token='')=>{retired.add(ref);const existed=pending.some(p=>p.ref===ref);const own=pending.find(p=>p.ref===ref);phone.dismissFinishedCall?.(ref,token||own?.callToken||'');pending=pending.filter(p=>p.ref!==ref);persist();render();if(existed)refreshNotices();};\n const open=()=>{if(phone.getPhoneStatus().active||!crm)return;\n  const state=phone.getCallState(),ref=reference(state);\n  const task=state?.phase==='incoming-finished'||state?.phase==='finished'?pending.find(x=>x.ref===ref):pending[0];\n  const url=new URL(cfg.salesHistoryUrl,location.href);\n  // Sin referencia PBX confirmada sólo puede consultarse el historial.\n  if(!task)return;\n  if(!metadata?.finalizada||metadata.pbx_call_id!==task.ref){void lookup(task.ref,revision);return;}\n  url.searchParams.set('pbx_call_id',task.ref);if(task.extension)url.searchParams.set('extension',task.extension);\n  if(crm.src===url.href){crm.contentWindow?.postMessage({type:'IMPE_SALES_RESULT_OPEN',ref:task.ref,extension:task.extension},location.origin);return;}\n  crm.src=url.href;\n };"," const remove=(ref,token='')=>{retired.add(ref);const existed=pending.some(p=>p.ref===ref);const own=pending.find(p=>p.ref===ref);phone.dismissFinishedCall?.(ref,token||own?.callToken||'');pending=pending.filter(p=>p.ref!==ref);persist();render();if(existed)refreshNotices();};\n const open=()=>{if(phone.getPhoneStatus().active||!crm)return;\n  const state=phone.getCallState(),ref=reference(state);\n  const task=(state?.phase==='incoming-finished'||state?.phase==='finished')?\n   (pending.find(x=>x.ref===ref)||provisional(state)):pending[0];\n  const url=new URL(cfg.salesHistoryUrl,location.href);\n  // Sin referencia PBX confirmada sólo puede consultarse el historial.\n  if(!task)return;\n  if((!metadata?.finalizada||metadata.pbx_call_id!==task.ref)&&\n   !(sipFinished(state)&&provisional(state)?.ref===task.ref)){\n   void lookup(task.ref,revision);return;\n  }\n  url.searchParams.set('pbx_call_id',task.ref);if(task.extension)url.searchParams.set('extension',task.extension);\n  if(sipFinished(state)&&(!metadata?.finalizada||metadata.pbx_call_id!==task.ref))url.searchParams.set('esperando_pbx','1');\n  if(crm.src===url.href){crm.contentWindow?.postMessage({type:'IMPE_SALES_RESULT_OPEN',ref:task.ref,extension:task.extension},location.origin);return;}\n  crm.src=url.href;\n };"],[" const render=()=>{const state=phone.getCallState(),active=Boolean(state?.active),form=phone.isResultFormOpen();\n  const ref=reference(state);\n  const finished=!active&&['finished','incoming-finished'].includes(state?.phase);\n  const task=finished?pending.find(x=>x.ref===ref):pending[0];\n  const confirmed=Boolean(task&&metadata?.pbx_call_id===task.ref&&metadata.finalizada);\n  button.hidden=active||form||(!finished&&!pending.length);\n  button.disabled=!confirmed;\n  button.textContent=confirmed?'Registrar resultado':'Conciliando llamada';\n  const icon=document.createElement('i');icon.className='bi bi-journal-check';icon.setAttribute?.('aria-hidden','true');button.prepend?.(icon);\n  button.dataset.salesPending=task?'1':'0';\n };"," const render=()=>{const state=phone.getCallState(),active=Boolean(state?.active),form=phone.isResultFormOpen();\n  const ref=reference(state);\n  const finished=!active&&['finished','incoming-finished'].includes(state?.phase);\n  const instant=provisional(state);\n  const task=finished?(pending.find(x=>x.ref===ref)||instant):pending[0];\n  const confirmed=Boolean(task&&metadata?.pbx_call_id===task.ref&&metadata.finalizada);\n  const canOpen=confirmed||Boolean(instant&&task?.ref===instant.ref);\n  button.hidden=active||form||(!finished&&!pending.length&&!instant);\n  button.disabled=!canOpen;\n  button.textContent=canOpen?'Registrar resultado':'Conciliando llamada';\n  const icon=document.createElement('i');icon.className='bi bi-journal-check';icon.setAttribute?.('aria-hidden','true');button.prepend?.(icon);\n  button.dataset.salesPending=task?'1':'0';\n };"],["  if(state.active&&!metadata){\n   const accepted=acceptedFor(state,ref);\n   if(accepted){\n    candidate={id:Number(accepted.id),token:state.callToken};\n    name.textContent=accepted.nombre;\n    hint.textContent='Nombre informado en disponibilidad · Ext. '+accepted.origen_extension;\n    card.hidden=false;phone.bindTransferCandidate?.(Number(accepted.id));","  if(state.active&&!metadata){\n   const accepted=acceptedFor(state,ref);\n   if(accepted){\n    candidate={id:Number(accepted.id),token:state.callToken,ref:accepted.referencia};\n    name.textContent=accepted.nombre;\n    hint.textContent='Nombre informado en disponibilidad · Ext. '+accepted.origen_extension;\n    card.hidden=false;phone.bindTransferCandidate?.(Number(accepted.id));"]]},{"path":"public/javascript/telefonia_ventas_resultados.js","pairs":[[" const notify=(message,error=false)=>window.IMPE_TELEPHONY_NOTIFY?.(message,error);\n const request=async url=>{\n  const controller=new AbortController();requestController=controller;const timeout=setTimeout(()=>controller.abort(),10000);\n  try{const r=await fetch(url,{credentials:'same-origin',cache:'no-store',signal:controller.signal}),j=await r.json();if(!r.ok||!j.ok)throw Error(j.mensaje||'No se pudo consultar la llamada.');return j;}\n  catch(error){if(error.name==='AbortError')throw Error('La consulta tardó demasiado. Vuelve a comprobar el cierre.');throw error;}\n  finally{clearTimeout(timeout);if(requestController===controller)requestController=null;}\n };"," const notify=(message,error=false)=>window.IMPE_TELEPHONY_NOTIFY?.(message,error);\n const request=async url=>{\n  const controller=new AbortController();requestController=controller;const timeout=setTimeout(()=>controller.abort(),10000);\n  try{const r=await fetch(url,{credentials:'same-origin',cache:'no-store',signal:controller.signal}),j=await r.json();if(!r.ok||!j.ok){const e=Error(j.mensaje||'No se pudo consultar la llamada.');e.status=r.status;throw e;}return j;}\n  catch(error){if(error.name==='AbortError')throw Error('La consulta tardó demasiado. Vuelve a comprobar el cierre.');throw error;}\n  finally{clearTimeout(timeout);if(requestController===controller)requestController=null;}\n };"],["  if(opening||disposed)return;cancelRetry();opening=true;recheck.disabled=true;const version=++cycle;\n  try{\n   const url=new URL(root.dataset.detailUrl,location.href);url.searchParams.set('pbx_call_id',ref);if(ext)url.searchParams.set('extension',ext);\n   const data=await request(url);if(version!==cycle)return;\n   call=data.llamada;\n   if(shell()&&!shell().beginCallResult(token,[ref]))throw Error('Termina la llamada activa antes de abrir este registro.');\n   if(!call.finalizada){","  if(opening||disposed)return;cancelRetry();opening=true;recheck.disabled=true;const version=++cycle;\n  try{\n   const url=new URL(root.dataset.detailUrl,location.href);url.searchParams.set('pbx_call_id',ref);if(ext)url.searchParams.set('extension',ext);\n   let data;\n   try{data=await request(url);}\n   catch(error){\n    // La invitación SIP sí terminó, pero el tramo saliente 100→150\n    // puede aún no estar materializado en la tabla comercial. Sólo el\n    // caso explícito de apertura provisional y HTTP 409 espera al PBX.\n    const provisional=new URL(location.href).searchParams.get('esperando_pbx')==='1';\n    if(provisional&&error.status===409&&version===cycle){\n     if(shell()&&!shell().beginCallResult(token,[ref]))throw Error('Termina la llamada activa antes de registrar.');\n     waiting=true;fields.hidden=true;submit.hidden=true;\n     recheck.hidden=attempt<4;\n     form.querySelector('[data-sales-form-state]').textContent='Finalizada en el teléfono · conciliando PBX';\n     feedback.textContent=attempt<4?\n      'Puedes continuar con el registro en cuanto Zadarma confirme esta transferencia. El sistema lo comprobará automáticamente.':\n      'Zadarma aún no confirma el tramo. No se ha guardado ningún resultado.';\n     bootstrap.Modal.getOrCreateInstance(modal).show();\n     if(attempt<4)timer=setTimeout(()=>{if(version===cycle&&!disposed)void open(ref,ext,attempt+1);},1500*2**attempt);\n     return;\n    }\n    throw error;\n   }\n   if(version!==cycle)return;\n   call=data.llamada;\n   if(shell()&&!shell().beginCallResult(token,[ref]))throw Error('Termina la llamada activa antes de abrir este registro.');\n   if(!call.finalizada){"],["   else{shell()?.endCallResult(token);notify(e.message,true);}\n  }finally{opening=false;recheck.disabled=false;}\n }\n recheck.addEventListener('click',()=>{if(call)void open(call.pbx_call_id,call.extension);});\n root.addEventListener('click',async e=>{\n  const button=e.target.closest('[data-sales-program-complete]');if(!button||button.disabled)return;\n  if(!window.confirm('¿Cerrar este recordatorio? No se registrará una llamada ni cambiará su resultado.'))return;","   else{shell()?.endCallResult(token);notify(e.message,true);}\n  }finally{opening=false;recheck.disabled=false;}\n }\n recheck.addEventListener('click',()=>{if(call)void open(call.pbx_call_id,call.extension);else{const u=new URL(location.href);if(u.searchParams.get('esperando_pbx')==='1'&&u.searchParams.get('pbx_call_id'))void open(u.searchParams.get('pbx_call_id'),u.searchParams.get('extension')||'');}});\n root.addEventListener('click',async e=>{\n  const button=e.target.closest('[data-sales-program-complete]');if(!button||button.disabled)return;\n  if(!window.confirm('¿Cerrar este recordatorio? No se registrará una llamada ni cambiará su resultado.'))return;"]]}]''')
HOME=Path.home()
LIVE=Path(os.environ.get("IMPE_VENTAS_SALIENTE_PUBLIC",str(HOME/"domains/fundacionrededumex.org/public_html")))
BACKUPS=Path(os.environ.get("IMPE_VENTAS_SALIENTE_BACKUPS",str(HOME/"respaldos-crm")))
MODE=sys.argv[1] if len(sys.argv)>1 else "--check"
if MODE not in ("--check","--apply","--restore") or len(sys.argv)!=(3 if MODE=="--restore" else (2 if len(sys.argv)>1 else 1)):
    raise SystemExit("Uso: --check | --apply | --restore RUTA_RESPALDO")
def fail(message):
    raise RuntimeError(message)
def digest(v):
    return hashlib.sha256(v).hexdigest()
def no_symlinks(path):
    for part in (path,*path.parents):
        if part.is_symlink():fail("Enlace simbólico no permitido: "+str(part))
def atomically_write(path,data,mode):
    fd,tmp=tempfile.mkstemp(prefix=".impe-ventas-stage-",dir=str(path.parent))
    try:
        os.fchmod(fd,mode)
        with os.fdopen(fd,"wb") as stream:
            stream.write(data);stream.flush();os.fsync(stream.fileno())
        os.replace(tmp,path)
    finally:
        if os.path.exists(tmp):os.unlink(tmp)
def lint_blob(filename,contents):
    kind="php" if filename.endswith(".php") else "js"
    if kind=="js" and not shutil.which("node"):
        print("AVISO: Node no está disponible en Hostinger; validar sintaxis JS en GitHub antes de publicar: "+filename)
        return
    program=["php","-l"] if kind=="php" else ["node","--check"]
    fd,tmp=tempfile.mkstemp(suffix="."+kind)
    try:
        with os.fdopen(fd,"wb") as stream:stream.write(contents)
        result=subprocess.run(program+[tmp],stdout=subprocess.PIPE,stderr=subprocess.PIPE,universal_newlines=True)
        if result.returncode!=0:
            fail("Sintaxis no válida ("+filename+"): "+(result.stdout+result.stderr)[-1500:])
    finally:
        if os.path.exists(tmp):os.unlink(tmp)
def patch(contents,pairs,path):
    crlf=b"\r\n" in contents
    if crlf and contents.count(b"\r\n")!=contents.count(b"\n"):
        fail("Finales de línea mixtos: "+path)
    current=contents.decode("utf-8").replace("\r\n","\n")
    modified=current
    for old,new in pairs:
        if modified.count(new)==1:continue
        if modified.count(old)!=1:
            fail("CONFLICTO: versión desconocida en "+path+" SHA256="+digest(contents)+". No se modificó ningún archivo.")
        modified=modified.replace(old,new,1)
    if modified==current:return contents,False
    return (modified.replace("\n","\r\n") if crlf else modified).encode("utf-8"),True
no_symlinks(LIVE)
if not (LIVE/"index.php").is_file():fail("No se encontró la raíz del CRM")
no_symlinks(BACKUPS)
LIVE=LIVE.resolve(strict=True);BACKUPS=BACKUPS.resolve(strict=False)
if LIVE==BACKUPS or LIVE in BACKUPS.parents or BACKUPS in LIVE.parents:
    fail("Los respaldos deben estar fuera del sitio")
allowed={row["path"]:row for row in PATCHES}
if len(allowed)!=4 or len(PATCHES)!=4:fail("Manifiesto fuera de alcance")
targets={}
for rel in allowed:
    p=LIVE/rel
    no_symlinks(p)
    if not p.is_file():fail("No existe "+rel)
    targets[rel]=p
lock=LIVE.parent/".impe-atencion-deploy.lock"
if MODE=="--restore":
    backup=Path(sys.argv[2]);no_symlinks(backup);backup=backup.resolve(strict=True)
    if BACKUPS not in backup.parents:fail("Respaldo fuera del directorio autorizado")
    manifest=json.loads((backup/"manifest.json").read_text("utf-8"))
    if manifest.get("tag")!=TAG:fail("Manifiesto perteneciente a otra versión")
    if not manifest.get("files"):fail("Manifiesto vacío")
    changes={}
    for rel,row in manifest["files"].items():
        if rel not in targets:fail("Archivo no autorizado en el manifiesto")
        old=(backup/rel).read_bytes();now=targets[rel].read_bytes()
        if digest(old)!=row["before"] or digest(now)!=row["after"]:
            fail("CONFLICTO: archivo cambiado tras publicar: "+rel)
        lint_blob(rel,old)
        changes[rel]=(now,old,row["mode"])
    lock.mkdir(mode=0o700)
    try:
        for rel,(now,old,_) in changes.items():
            if targets[rel].read_bytes()!=now:fail("Archivo modificado antes de restaurar: "+rel)
        for rel,(_,old,perm) in changes.items():atomically_write(targets[rel],old,perm)
        print("OK: respaldo restaurado. Archivos:",len(changes))
    finally:lock.rmdir()
    raise SystemExit(0)
changes={}
for row in PATCHES:
    rel=row["path"];orig=targets[rel].read_bytes()
    target,needed=patch(orig,row["pairs"],rel)
    if needed:
        lint_blob(rel,target)
        changes[rel]=(orig,target,stat.S_IMODE(targets[rel].stat().st_mode))
        print("PREPARADO:",rel)
    else:print("YA APLICADO:",rel)
if MODE=="--check":
    print("OK: revisión compatible; sitio intacto. Archivos a modificar:",len(changes))
    raise SystemExit(0)
if not changes:
    print("OK: corrección ya aplicada, ningún archivo modificado")
    raise SystemExit(0)
BACKUPS.mkdir(parents=True,exist_ok=True)
lock.mkdir(mode=0o700)
backup=None;written=[]
try:
    for rel,(orig,_,_) in changes.items():
        if targets[rel].read_bytes()!=orig:fail("El CRM cambió durante la revisión: "+rel)
    backup=BACKUPS/("ventas-saliente-modal-"+datetime.datetime.now().strftime("%Y%m%d-%H%M%S")+"-"+str(os.getpid()))
    backup.mkdir(mode=0o700)
    manifest={"tag":TAG,"files":{}}
    for rel,(orig,after,perm) in changes.items():
        dst=backup/rel;dst.parent.mkdir(parents=True,exist_ok=True);dst.write_bytes(orig)
        if digest(dst.read_bytes())!=digest(orig):fail("Respaldo incompleto: "+rel)
        manifest["files"][rel]={"before":digest(orig),"after":digest(after),"mode":perm}
    (backup/"manifest.json").write_text(json.dumps(manifest,indent=2),encoding="utf-8")
    for rel,(orig,_,_) in changes.items():
        if targets[rel].read_bytes()!=orig:fail("El CRM cambió durante el respaldo: "+rel)
    for rel,(orig,after,perm) in changes.items():
        atomically_write(targets[rel],after,perm);written.append(rel)
    print("OK: --apply terminado. Archivos:",len(changes),"Sin SQL ni audio.")
    print("Respaldo:",backup)
    print("Rollback: bash",os.environ["IMPE_PUBLISHER_PATH"],"--restore",backup)
except Exception:
    for rel in reversed(written):
        orig,after,perm=changes[rel]
        if targets[rel].read_bytes()==after:atomically_write(targets[rel],orig,perm)
    raise
finally:lock.rmdir()
PY
