#!/usr/bin/env bash
# Parche selectivo de telefonía: identidad SIP, cierre local y audio en segundo plano.
# No modifica SQL, PHP, credenciales ni archivos ajenos.
set -euo pipefail
umask 077
IMPE_PUBLISHER_PATH="$(realpath "$0")" python3 - "$@" <<'PY'
import hashlib, json, os, sys, shutil, tempfile, datetime
from pathlib import Path

TAG = "impe-telefono-20261020-d34883b6"
PATCHES = json.loads(r'''[{"path":"public/javascript/telefonia_host.js","pairs":[["                onStopped: function () {\n                    if(transferFlow?.holdsIncoming()){void transferFlow.tick();return;}\n                    if (!isSdkOnlyIncoming()) return;\n                    // Al atender, el SDK deja de timbrar pero la conversación\n                    // CONTINÚA. Sólo limpiar un timbrado que no se contestó.\n                    if (state.phase === 'incoming-ringing') {\n                        clearSdkOnlyIncoming();\n                    }\n                },\n","                onStopped: function () {\n                    if(transferFlow?.holdsIncoming()){void transferFlow.tick();return;}\n                    // La desaparición de las clases CSS del timbre no\n                    // acredita END SIP ni fin de PUSH. Antes esto borraba\n                    // el modal de un intento legítimo sin dejar resultado.\n                    // onIdle procesa la terminación verificada por el SDK.\n                },\n"],["                        publish({active:false, phase:'incoming-finished',\n                            status:attachedPbx?'ended-unconfirmed':'sdk-ended', duration,\n                            context:{...state.context,sdkEnded:true},\n                            message:attachedPbx\n                                ? 'La sesión SIP terminó. El cierre PBX sigue pendiente de confirmación.'\n                                : 'Sesión SIP finalizada. El historial PBX se actualiza por separado.'});\n","                        publish({active:false, phase:'incoming-finished',\n                            status:attachedPbx?'ended-unconfirmed':(wasAnswered?'sdk-ended':'no-answer'), duration,\n                            context:{...state.context,sdkEnded:true},\n                            message:attachedPbx\n                                ? 'La sesión SIP terminó. El cierre PBX sigue pendiente de confirmación.'\n                                : 'Sesión SIP finalizada. El historial PBX se actualiza por separado.'});\n"],["                    if (isSdkOnlyIncoming()) {\n                        if(state.phase==='incoming-ringing')clearSdkOnlyIncoming();\n                        else{publish({phase:'recovering',status:'recovering',message:'Se interrumpió el audio del navegador. La centralita debe confirmar el estado.'});void pollIncoming();}\n                        return;\n                    }\n","                    if (isSdkOnlyIncoming()) {\n                        if(state.phase==='incoming-ringing') {\n                            // Reposo observado sin prueba de cierre SIP/PUSH:\n                            // no descartar la llamada como si nunca existió.\n                            // El cierre real se procesa arriba con sessionEnded\n                            // o pushEnded, dejando constancia de no contestada.\n                            publish({phase:'finishing',status:'ending',duration:0,\n                                message:'El timbrado terminó. Comprobando el cierre de la llamada.'});\n                            void pollIncoming();\n                        } else {\n                            publish({phase:'recovering',status:'recovering',\n                                message:'Se interrumpió el audio del navegador. La centralita debe confirmar el estado.'});\n                            void pollIncoming();\n                        }\n                        return;\n                    }\n"]]},{"path":"public/javascript/telefonia_disponibilidad.js","pairs":[[" let watched=null,received=null,polling=false,acting=false,transferring=false,callbacks=-1,deferred='';\n"," let watched=null,received=null,polling=false,acting=false,transferring=false,callbacks=-1,deferred='';\n // Única aceptación vigente de este destinatario; nunca se usa el nombre\n // histórico del teléfono para identificar la próxima invitación SIP.\n let acceptedTransfer=null;\n"],[" const respond=async which=>{\n  if(acting||!received)return;acting=true;stopSound();\n  const buttons=incoming.querySelectorAll('button');buttons.forEach(b=>b.disabled=true);\n  try{if(which==='ACEPTAR'&&busy())throw Error('Ya tienes una llamada o tu teléfono no está conectado.');await action(received,which);closeIncoming();}\n  catch(e){$('[data-availability-feedback]').textContent=e.message;}\n  finally{acting=false;buttons.forEach(b=>b.disabled=false);}\n };\n"," const respond=async which=>{\n  if(acting||!received)return;acting=true;stopSound();\n  const buttons=incoming.querySelectorAll('button');buttons.forEach(b=>b.disabled=true);\n  try{\n   if(which==='ACEPTAR'&&busy())throw Error('Ya tienes una llamada o tu teléfono no está conectado.');\n   const op=received,confirmed=await action(op,which);\n   if(which==='ACEPTAR'&&confirmed?.estado==='ACEPTADA'){\n    acceptedTransfer={id:String(op.id),referencia:String(op.referencia||''),\n      numero:String(op.numero||''),nombre:String(op.institucion_nombre||'').slice(0,150),\n      origen_extension:String(op.origen_extension||''),at:Date.now()};\n   }\n   closeIncoming();\n  }\n  catch(e){$('[data-availability-feedback]').textContent=e.message;}\n  finally{acting=false;buttons.forEach(b=>b.disabled=false);}\n };\n"],[" window.IMPE_AVAILABILITY={watch:store,cancel,audioDiagnostic,pending:()=>Boolean(watched&&matchesCall(watched)&&['PENDIENTE','ACEPTADA','ENVIANDO','ENVIADA','TRANSFERIDA','SIN_RESPUESTA','SIN_CONFIRMAR','DEVOLUCION'].includes(watched.estado)),tick};\n"," window.IMPE_AVAILABILITY={watch:store,cancel,audioDiagnostic,\n  acceptedTransfer:()=>acceptedTransfer&&Date.now()-acceptedTransfer.at<90000?{...acceptedTransfer}:null,\n  pending:()=>Boolean(watched&&matchesCall(watched)&&['PENDIENTE','ACEPTADA','ENVIANDO','ENVIADA','TRANSFERIDA','SIN_RESPUESTA','SIN_CONFIRMAR','DEVOLUCION'].includes(watched.estado)),tick};\n"]]},{"path":"public/javascript/telefonia_ventas_shell.js","pairs":[[" let pending=[],previous=null,scope='',revision=0,fetching=false,metadata=null;\n"," let pending=[],previous=null,scope='',revision=0,fetching=false,metadata=null;\n let acceptedBound=null;\n const digits10=value=>String(value||'').replace(/\\D/g,'').slice(-10);\n // El nombre aceptado pertenece a una solicitud única, no al último\n // número marcado. Se ata una sola vez a la nueva invitación SDK o a su\n // referencia PBX exacta y no se hereda en llamadas consecutivas.\n const acceptedFor= (state,ref)=>{\n  const op=window.IMPE_AVAILABILITY?.acceptedTransfer?.();\n  if(!op?.nombre||!valid(op.referencia)||!state?.active||state.direction!=='incoming')return null;\n  const token=String(state.callToken||''),started=Number(state.requestedAt||0)*1000;\n  if(!token||!started||started<op.at-20000||started>op.at+90000)return null;\n  if(ref&&ref!==op.referencia)return null;\n  if(!ref&&state.context?.type!=='SDK_INCOMING')return null;\n  const caller=digits10(state.destination),expected=digits10(op.numero);\n  if(caller.length===10&&expected.length===10&&caller!==expected)return null;\n  if(acceptedBound&&acceptedBound.id!==op.id)acceptedBound=null;\n  if(acceptedBound&&!ref&&acceptedBound.token!==token)return null;\n  if(!acceptedBound)acceptedBound={id:op.id,token};\n  return op;\n };\n"],["  if(state.active&&identity!==scope){scope=identity;++revision;metadata=null;card.hidden=true;name.textContent='';hint.textContent='';lastLookup=0;tries=0;}\n","  if(state.active&&identity!==scope){scope=identity;++revision;metadata=null;card.hidden=true;name.textContent='';hint.textContent='';lastLookup=0;tries=0;}\n  if(state.active&&!metadata){\n   const accepted=acceptedFor(state,ref);\n   if(accepted){\n    name.textContent=accepted.nombre;\n    hint.textContent='Nombre informado en disponibilidad · Ext. '+accepted.origen_extension;\n    card.hidden=false;\n   }\n  }\n"]]},{"path":"public/javascript/telefonia_shell.js","pairs":[["            incomingState.textContent = finishedIncoming\n                ? (phoneState.status === 'transferred'\n                    ? 'Finalizada · transferencia confirmada por la centralita'\n                    : phoneState.status === 'ended-unconfirmed'\n                    ? 'Llamada finalizada · actualizando el historial'\n                    : 'Finalizada · llamada concluida')\n                : shownOutgoing\n                ? (finished ? 'Finalizada · registrada en el historial telefónico'\n                    : (finishing ? 'Finalizando la conversación…' :\n                    (dialing ? (phoneState.phase==='ringing'?'Esperando que contesten':'Marcando desde la extensión ' + cfg.extension)\n                        : 'Conversación en curso')))\n                : (finishingIncoming ? 'Esperando cierre de Zadarma…' :\n                    (waitingAnswer ? 'Conectando audio…' :\n                    (ringing ? 'Llamada entrante a la extensión ' + cfg.extension\n                    : 'Conversación en curso')));\n","            incomingState.textContent = finishedIncoming\n                ? (phoneState.status === 'transferred'\n                    ? 'Finalizada · transferencia confirmada por la centralita'\n                    : phoneState.status === 'ended-unconfirmed'\n                    ? 'Llamada finalizada · actualizando el historial'\n                    : phoneState.status === 'no-answer'\n                    ? 'No contestada · el timbrado finalizó'\n                    : 'Finalizada · llamada concluida')\n                : shownOutgoing\n                ? (finished ? 'Finalizada · registrada en el historial telefónico'\n                    : (finishing ? 'Finalizando la conversación…' :\n                    (dialing ? (phoneState.phase==='ringing'?'Esperando que contesten':'Marcando desde la extensión ' + cfg.extension)\n                        : 'Conversación en curso')))\n                : (finishingIncoming ? 'Esperando cierre de Zadarma…' :\n                    (waitingAnswer ? 'Conectando audio…' :\n                    (ringing ? 'Llamada entrante a la extensión ' + cfg.extension\n                    : 'Conversación en curso')));\n"]]}]''')
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
def patch(raw, pairs, filename):
    crlf = b"\r\n" in raw
    if crlf and raw.count(b"\n") != raw.count(b"\r\n"):
        fail("Finales de línea mixtos: " + filename)
    content = raw.decode("utf-8").replace("\r\n","\n")
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
    if rel not in ("public/javascript/telefonia_host.js","public/javascript/telefonia_disponibilidad.js","public/javascript/telefonia_ventas_shell.js","public/javascript/telefonia_shell.js"):
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
