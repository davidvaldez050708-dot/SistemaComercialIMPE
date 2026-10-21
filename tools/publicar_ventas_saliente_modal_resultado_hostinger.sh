#!/usr/bin/env bash
# Reparación quirúrgica de Ventas: OUT_END saliente y descarte del modal.
# Compatible con Python 3.6 en Hostinger, sin SQL/estructura ni audio.
set -euo pipefail
umask 077
IMPE_PUBLISHER_PATH="$(realpath "$0")" python3 - "$@" <<'PY'
import hashlib, json, os, sys, tempfile, subprocess, stat, datetime, shutil
from pathlib import Path
TAG="impe-ventas-saliente-modal-20261021-v1"
PATCHES=json.loads(r'''[{"path":"app/services/TelefoniaVentasLlamadasService.php","kind":"php","pairs":[["        $answer=false;$end=false;$duration=null;$disposition='';\n        // La consulta se ordena por id ASC. Un cierre OUT_END del origen\n        // sólo puede cerrar al destinatario después de START de Recepción\n        // y ANSWER de Ventas del mismo PBX (con origen persistido).\n        $sourceStarted=false;$answeredAfterSource=false;\n        foreach($events as $e) {\n            $type=(string)($e['evento']??'');\n            $internal=trim((string)($e['internal']??''));\n            if($source!==''&&$source!==$ext&&$type==='NOTIFY_OUT_START'&&$internal===$source)$sourceStarted=true;\n            if($type==='NOTIFY_ANSWER'&&$internal===$ext){\n                $answer=true;\n                if($sourceStarted)$answeredAfterSource=true;\n            }\n            $p=json_decode((string)($e['payload_json']??''),true)?:[];\n            $own=$internal===$ext;\n            // Zadarma conserva internal del iniciador; last_internal puede\n            // ser el destinatario 150, quedar vacío o mantener al origen 100.\n            // Nunca aceptar aquí last_internal de una tercera extensión.\n            $last=trim((string)($e['last_internal']??''));if($last==='')$last=trim((string)($p['last_internal']??''));\n            $explicitEnd=$last===$ext;\n            $sourceEnd=$sourceStarted&&$answeredAfterSource&&($last===''||$last===$source)&&\n                $type==='NOTIFY_OUT_END'&&$internal===$source;\n            $type=$e['evento']??'';\n            if($own&&$type==='NOTIFY_ANSWER')$answer=true;\n","        $answer=false;$end=false;$duration=null;$disposition='';\n        // La consulta se ordena por id ASC. Un cierre OUT_END del origen\n        // sólo puede cerrar al destinatario después de START de Recepción\n        // y ANSWER de Ventas del mismo PBX (con origen persistido).\n        $sourceStarted=false;$answeredAfterSource=false;\n        foreach($events as $e) {\n            $type=(string)($e['evento']??'');\n            $internal=trim((string)($e['internal']??''));\n            if($source!==''&&$source!==$ext&&$type==='NOTIFY_OUT_START'&&$internal===$source)$sourceStarted=true;\n            if($type==='NOTIFY_ANSWER'&&$internal===$ext){\n                $answer=true;\n                if($sourceStarted)$answeredAfterSource=true;\n            }\n            $p=json_decode((string)($e['payload_json']??''),true)?:[];\n            $own=$internal===$ext;\n            // Zadarma conserva internal del iniciador; last_internal puede\n            // ser el destinatario 150, quedar vacío o mantener al origen 100.\n            // Nunca aceptar aquí last_internal de una tercera extensión.\n            $last=trim((string)($e['last_internal']??''));if($last==='')$last=trim((string)($p['last_internal']??''));\n            $explicitEnd=$last===$ext;\n            $sourceEnd=$sourceStarted&&$answeredAfterSource&&($last===''||$last===$source)&&\n                $type==='NOTIFY_OUT_END'&&$internal===$source;\n            $type=$e['evento']??'';\n            if($own&&$type==='NOTIFY_ANSWER')$answer=true;\n"]]},{"path":"public/javascript/telefonia_shell.js","kind":"js","pairs":[["        dismissFinishedCall: function (ref) {\n            if(currentState?.active)return false;\n            const matches=s=>[s?.pbxCallId,s?.callToken,s?.finalMetadata?.pbx_call_id,s?.context?.transferReference].includes(ref);\n            if(recentFinishedIncoming&&matches(recentFinishedIncoming.state))recentFinishedIncoming=null;\n            if(recentFinishedOutgoing&&matches(recentFinishedOutgoing.state))recentFinishedOutgoing=null;\n            if(resultFormOwner?.refs.includes(ref))resultFormOwner=null;\n            render();return true;\n        },\n","        dismissFinishedCall: function (ref, token) {\n            if(currentState?.active)return false;\n            // La transferencia saliente puede terminar con SDK ID en el\n            // resumen local aunque el resultado autorizado ya tenga PBX ID.\n            // Aceptar sólo la referencia exacta o el token SDK de ese\n            // resultado; el número telefónico no participa en la identidad.\n            const same=s=>Boolean(s&&(\n                [s.pbxCallId,s.finalMetadata?.pbx_call_id,s.context?.transferReference].includes(ref)||\n                (token&&s.callToken===token)\n            ));\n            let dismissed=false;\n            if(recentFinishedIncoming&&same(recentFinishedIncoming.state)){\n                recentFinishedIncoming=null;dismissed=true;\n            }\n            if(recentFinishedOutgoing&&same(recentFinishedOutgoing.state)){\n                recentFinishedOutgoing=null;dismissed=true;\n            }\n            if(resultFormOwner?.refs.includes(ref)){\n                resultFormOwner=null;dismissed=true;\n            }\n            // Limpiar también el resumen retenido por el host del MISMO\n            // diálogo; el host verifica el token y nunca cuelga el audio.\n            if(same(currentState)&&['incoming-finished','finished'].includes(currentState.phase)){\n                sendPhoneCommand('CLEAR_FINISHED',{callToken:currentState.callToken});\n                dismissed=true;\n            }\n            if(dismissed)render();\n            return dismissed;\n        },\n"]]},{"path":"public/javascript/telefonia_ventas_shell.js","kind":"js","pairs":[[" const remove=ref=>{retired.add(ref);const existed=pending.some(p=>p.ref===ref);phone.dismissFinishedCall?.(ref);pending=pending.filter(p=>p.ref!==ref);persist();render();if(existed)refreshNotices();};\n"," const remove=(ref,token='')=>{retired.add(ref);const existed=pending.some(p=>p.ref===ref);const own=pending.find(p=>p.ref===ref);phone.dismissFinishedCall?.(ref,token||own?.callToken||'');pending=pending.filter(p=>p.ref!==ref);persist();render();if(existed)refreshNotices();};\n"],["  const task=pending.find(p=>p.ref===e.data.ref&&p.extension===e.data.extension);remove(e.data.ref);\n  if(task&&!phone.getPhoneStatus().active){try{localStorage.setItem('impe:telephony:command:'+cfg.userId,JSON.stringify({type:'COMMAND',action:'CLEAR_FINISHED',payload:{callToken:task.callToken},id:'sales-clear-'+Date.now()}));}catch(_){}}\n","  const task=pending.find(p=>p.ref===e.data.ref&&p.extension===e.data.extension);\n  // El mensaje del iframe es sólo UX. Sin pendiente del propietario exacto,\n  // no borrar un resumen que pueda pertenecer a otra llamada.\n  if(!task)return;\n  remove(e.data.ref,task.callToken||'');\n  if(task.callToken&&!phone.getPhoneStatus().active){try{localStorage.setItem('impe:telephony:command:'+cfg.userId,JSON.stringify({type:'COMMAND',action:'CLEAR_FINISHED',payload:{callToken:task.callToken},id:'sales-clear-'+Date.now()}));}catch(_){}}\n"]]}]''')
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
if len(allowed)!=3 or len(PATCHES)!=3:fail("Manifiesto fuera de alcance")
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
