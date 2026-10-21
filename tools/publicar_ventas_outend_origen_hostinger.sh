#!/usr/bin/env bash
# Parche selectivo: atribución de NOTIFY_OUT_END 100 → 150.
# No toca SQL, CSS, HTML, audio ni las llamadas entrantes transferidas.
set -euo pipefail
umask 077
IMPE_PUBLISHER_PATH="$(realpath "$0")" python3 - "$@" <<'PY'
from pathlib import Path
import hashlib, json, os, sys, tempfile, subprocess, stat, datetime

TAG="impe-ventas-outend-origen-20261021-v1"
REL="app/services/TelefoniaVentasLlamadasService.php"
OLD=json.loads(r'''"        $answer=false;$end=false;$duration=null;$disposition='';\n        $outboundSource=false;\n        foreach($events as $e){\n            if(($e['evento']??'')==='NOTIFY_ANSWER'&&trim((string)($e['internal']??''))===$ext)$answer=true;\n            if($source!==''&&($e['evento']??'')==='NOTIFY_OUT_START'&&trim((string)($e['internal']??''))===$source)$outboundSource=true;\n        }\n        foreach($events as $e) {\n            $p=json_decode((string)($e['payload_json']??''),true)?:[];\n            $own=trim((string)($e['internal']??''))===$ext;\n            // Zadarma conserva internal del iniciador y señala el destinatario\n            // final con last_internal. No exigir internal vacío para este caso.\n            $last=trim((string)($e['last_internal']??''));if($last==='')$last=trim((string)($p['last_internal']??''));\n            $explicitEnd=$last===$ext;\n            // OUT_END termina la llamada externa. Sólo extender su cierre a\n            // un destinatario persistido que realmente contestó este mismo PBX.\n            $sourceEnd=$last===''&&$outboundSource&&$answer&&$source!==$ext&&\n                ($e['evento']??'')==='NOTIFY_OUT_END'&&trim((string)($e['internal']??''))===$source;\n            $type=$e['evento']??'';\n            if($own&&$type==='NOTIFY_ANSWER')$answer=true;\n"''')
NEW=json.loads(r'''"        $answer=false;$end=false;$duration=null;$disposition='';\n        // La consulta se ordena por id ASC. Un cierre OUT_END del origen\n        // sólo puede cerrar al destinatario después de START de Recepción\n        // y ANSWER de Ventas del mismo PBX (con origen persistido).\n        $sourceStarted=false;$answeredAfterSource=false;\n        foreach($events as $e) {\n            $type=(string)($e['evento']??'');\n            $internal=trim((string)($e['internal']??''));\n            if($source!==''&&$source!==$ext&&$type==='NOTIFY_OUT_START'&&$internal===$source)$sourceStarted=true;\n            if($type==='NOTIFY_ANSWER'&&$internal===$ext){\n                $answer=true;\n                if($sourceStarted)$answeredAfterSource=true;\n            }\n            $p=json_decode((string)($e['payload_json']??''),true)?:[];\n            $own=$internal===$ext;\n            // Zadarma conserva internal del iniciador; last_internal puede\n            // ser el destinatario 150, quedar vacío o mantener al origen 100.\n            // Nunca aceptar aquí last_internal de una tercera extensión.\n            $last=trim((string)($e['last_internal']??''));if($last==='')$last=trim((string)($p['last_internal']??''));\n            $explicitEnd=$last===$ext;\n            $sourceEnd=$sourceStarted&&$answeredAfterSource&&($last===''||$last===$source)&&\n                $type==='NOTIFY_OUT_END'&&$internal===$source;\n            $type=$e['evento']??'';\n            if($own&&$type==='NOTIFY_ANSWER')$answer=true;\n"''')
mode=sys.argv[1] if len(sys.argv)>1 else "--check"
if (mode in ("--check","--apply") and len(sys.argv)>2) or (mode=="--restore" and len(sys.argv)!=3) or mode not in ("--check","--apply","--restore"):
    raise SystemExit("Uso: --check | --apply | --restore RUTA_RESPALDO")
home=Path.home()
site=Path(os.environ.get("IMPE_VENTAS_OUTEND_PUBLIC",str(home/"domains/fundacionrededumex.org/public_html")))
root=Path(os.environ.get("IMPE_VENTAS_OUTEND_BACKUPS",str(home/"respaldos-crm")))
def fail(message):
    raise RuntimeError(message)
def sha(v):
    return hashlib.sha256(v).hexdigest()
def no_symlink(p):
    if any(item.is_symlink() for item in (p,*p.parents)):
        fail("Se detectó un enlace simbólico no autorizado: "+str(p))
def write_atomic(target,data,file_mode):
    fd,temp=tempfile.mkstemp(prefix=".impe-outend-",dir=str(target.parent))
    try:
        os.fchmod(fd,file_mode)
        with os.fdopen(fd,"wb") as stream:
            stream.write(data);stream.flush();os.fsync(stream.fileno())
        os.replace(temp,target)
    finally:
        if os.path.exists(temp):os.unlink(temp)
def lint(value):
    with tempfile.NamedTemporaryFile(suffix=".php",delete=False) as tmp:
        tmp.write(value);tmp.flush();name=tmp.name
    try:
        result=subprocess.run(["php","-l",name],capture_output=True,text=True)
        if result.returncode!=0:fail("Error sintáctico PHP: "+result.stderr.strip())
    finally:
        os.unlink(name)
no_symlink(site)
if not (site/"index.php").is_file():fail("No existe la raíz del CRM")
no_symlink(root)
site=site.resolve(strict=True);root=root.resolve(strict=False)
if site==root or site in root.parents or root in site.parents:fail("El respaldo debe estar fuera del sitio")
file=site/REL
no_symlink(file)
if not file.is_file():fail("No existe "+REL)
lock=site.parent/".impe-atencion-deploy.lock"
def patch(data):
    eol=b"\r\n" in data
    if eol and data.count(b"\r\n")!=data.count(b"\n"):fail("Finales de línea mixtos")
    src=data.decode("utf-8").replace("\r\n","\n")
    if src.count(NEW)==1:
        return data,False
    if src.count(OLD)!=1:
        fail("CONFLICTO: versión PHP desconocida. SHA256="+sha(data)+". SITIO INTACTO.")
    if src.count("public static function hechos(")!=1 or src.count("TelefoniaPbxSegmentosService::transferencia(")!=1:
        fail("CONFLICTO: estructura comercial desconocida")
    result=src.replace(OLD,NEW,1)
    if result.count(NEW)!=1 or result.count(OLD)!=0:fail("CONFLICTO: aplicación incompleta")
    return result.replace("\n","\r\n").encode("utf-8") if eol else result.encode("utf-8"),True
original=file.read_bytes();fmode=stat.S_IMODE(file.stat().st_mode)
if mode=="--restore":
    backup=Path(sys.argv[2])
    no_symlink(backup)
    backup=backup.resolve(strict=True)
    if root not in backup.parents:fail("Respaldo fuera de carpeta autorizada")
    meta=json.loads((backup/"manifest.json").read_text("utf-8"))
    if meta.get("tag")!=TAG:fail("Respaldo de otra entrega")
    oldbytes=(backup/REL).read_bytes()
    if sha(oldbytes)!=meta.get("original") or sha(original)!=meta.get("published"):
        fail("CONFLICTO: instalación cambió desde el respaldo; no restaurar")
    lint(oldbytes)
    lock.mkdir(mode=0o700)
    try:
        if file.read_bytes()!=original:fail("Cambió el archivo durante la comprobación")
        write_atomic(file,oldbytes,meta["file_mode"])
        print("OK: restauración completada:",REL)
    finally:lock.rmdir()
    raise SystemExit(0)
target,needed=patch(original)
if not needed:
    print("OK: corrección ya aplicada. Sitio intacto.")
    raise SystemExit(0)
lint(target)
if mode=="--check":
    print("PREPARADO:",REL)
    print("OK: revisión compatible; sitio intacto. 1 archivo PHP, sin SQL ni audio.")
    raise SystemExit(0)
root.mkdir(parents=True,exist_ok=True)
lock.mkdir(mode=0o700)
backup=None
try:
    if file.read_bytes()!=original:fail("El CRM cambió durante la revisión")
    backup=root/("ventas-outend-origen-"+datetime.datetime.now().strftime("%Y%m%d-%H%M%S")+"-"+str(os.getpid()))
    backup.mkdir(mode=0o700)
    safe=backup/REL
    safe.parent.mkdir(parents=True,exist_ok=True)
    safe.write_bytes(original)
    meta={"tag":TAG,"original":sha(original),"published":sha(target),"file_mode":fmode}
    (backup/"manifest.json").write_text(json.dumps(meta,indent=2),encoding="utf-8")
    if sha(safe.read_bytes())!=sha(original):fail("Respaldo inconsistente")
    if file.read_bytes()!=original:fail("Archivo modificado antes de instalar")
    write_atomic(file,target,fmode)
    if sha(file.read_bytes())!=sha(target):fail("Archivo instalado distinto")
    print("OK: --apply terminado. 1 archivo PHP. Sin SQL, JS, CSS ni audio.")
    print("Respaldo:",backup)
    print("Rollback: bash",os.environ["IMPE_PUBLISHER_PATH"],"--restore",backup)
except Exception:
    if backup is not None and file.read_bytes()==target:
        write_atomic(file,original,fmode)
    raise
finally:
    lock.rmdir()
PY
