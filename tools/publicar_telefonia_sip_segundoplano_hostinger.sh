#!/usr/bin/env bash
# Parche selectivo de telefonía: identidad SIP, cierre local y audio en segundo plano.
# No modifica SQL, PHP, credenciales ni archivos ajenos.
set -euo pipefail
umask 077
IMPE_PUBLISHER_PATH="$(realpath "$0")" python3 - "$@" <<'PY'
import hashlib, json, os, sys, shutil, tempfile, datetime
from pathlib import Path

TAG = "impe-sip-20261020-23a8ab66"
PATCHES = json.loads(r'''[{"path":"public/javascript/telefonia_host.js","pairs":[["        const params = new URLSearchParams({\n            since: String(\n                Math.floor(\n                    (Date.now() - 10 * 60 * 1000) /\n                    1000\n                )\n            )\n        });\n\n","        // Sin referencia PBX no buscar diez minutos de historia: el mismo\n        // número puede haber llamado o sido transferido varias veces. Sólo\n        // descubrir eventos recibidos alrededor del INVITE SIP actual.\n        // Con una referencia conocida la consulta sigue siendo exacta.\n        const unresolvedSip = state.active && state.direction === 'incoming' &&\n            state.context?.type === 'SDK_INCOMING' &&\n            !String(state.pbxCallId || state.context?.transferReference || '').trim();\n        const nowSeconds = Math.floor(Date.now() / 1000);\n        const discoverySince = unresolvedSip\n            ? Math.max(0, Number(state.requestedAt || nowSeconds) - 12)\n            : (state.active ? nowSeconds - 600 : nowSeconds - 25);\n        const params = new URLSearchParams({\n            since: String(discoverySince)\n        });\n\n"],["                    // END SIP del diálogo observado, no desaparición de CSS ni\n                    // pérdida de transporte. Liberar sólo la sesión local exacta.\n                    if((evidence.sessionEnded||evidence.pushEnded) && state.active && isSdkOnlyIncoming() &&\n                        (!evidence.sessionId || evidence.sessionId===state.context?.sdkInviteId)){\n                        const duration=currentDuration();answeredAtMs=0;\n                        retirePbxCall(String(state.pbxCallId||state.context?.transferReference||''));\n                        publish({active:false,phase:'incoming-finished',status:'sdk-ended',duration,\n                            context:{...state.context,sdkEnded:true},\n                            message:'Sesión SIP finalizada. El historial PBX se actualiza por separado.'});\n                        return;\n                    }\n","                    // Cerrar la interfaz local con END SIP verificable, incluso\n                    // cuando ya se adoptó una referencia PBX. Antes, la rama\n                    // SDK_INCOMING se liberaba pero INCOMING quedaba varios\n                    // minutos en «Esperando cierre de Zadarma» sin sesión SIP.\n                    // Este cierre NO declara que Zadarma haya terminado la\n                    // conversación ni altera la conciliación/historial PBX.\n                    const sipSession = window.zdrmWebPhone?.webCallSession;\n                    const observedId = String(evidence.sessionId || '');\n                    const boundId = String(state.context?.sdkInviteId || '');\n                    const sdkOnly = isSdkOnlyIncoming();\n                    const attachedPbx = state.context?.type === 'INCOMING' &&\n                        Boolean(String(state.pbxCallId || ''));\n                    const sameSip = Boolean(observedId && (\n                        (boundId && observedId === boundId) ||\n                        (!boundId && sipSession?.direction === 'incoming' &&\n                            String(sipSession.id || '') === observedId &&\n                            sipSession.isEnded?.())\n                    ));\n                    const sipAlive = sipSession?.direction === 'incoming' &&\n                        !sipSession.isEnded?.();\n                    const sdkOnlyMatches = sdkOnly &&\n                        (!observedId || (boundId && observedId === boundId) ||\n                            (sipSession?.direction === 'incoming' &&\n                                String(sipSession.id || '') === observedId &&\n                                sipSession.isEnded?.()));\n                    if ((evidence.sessionEnded || evidence.pushEnded) &&\n                        state.active && state.direction === 'incoming' &&\n                        !sipAlive && (sdkOnlyMatches || (attachedPbx && sameSip))) {\n                        const ref = String(state.pbxCallId || state.context?.transferReference || '');\n                        const wasAnswered = Boolean(state.context?.sdkConnected ||\n                            state.context?.pbxAnswered || answeredAtMs > 0);\n                        const duration = wasAnswered ? currentDuration() : 0;\n                        answeredAtMs = 0;\n                        if (ref) retirePbxCall(ref);\n                        publish({active:false, phase:'incoming-finished',\n                            status:attachedPbx?'ended-unconfirmed':'sdk-ended', duration,\n                            context:{...state.context,sdkEnded:true},\n                            message:attachedPbx\n                                ? 'La sesión SIP terminó. El cierre PBX sigue pendiente de confirmación.'\n                                : 'Sesión SIP finalizada. El historial PBX se actualiza por separado.'});\n                        ocultarHostEntrante();\n                        marcarPestanaEntrante('');\n                        return;\n                    }\n"]]},{"path":"public/javascript/telefonia_disponibilidad.js","pairs":[["  // No mezclar avisos con conversación SIP, pestaña oculta ni audio bloqueado.\n  const status=phone.getPhoneStatus();\n  if(!soundRequest||!audio||audio.state!=='running'||document.hidden||\n     !status.ready||status.active||Date.now()>=soundRequest.until)return;\n","  // Las solicitudes también deben avisar cuando el CRM está en otra pestaña.\n  // El bloqueo de autoplay se respeta mediante audio.state; la visibilidad\n  // no es motivo para silenciar una solicitud todavía pendiente.\n  const status=phone.getPhoneStatus();\n  if(!soundRequest||!audio||audio.state!=='running'||\n     !status.ready||status.active||Date.now()>=soundRequest.until)return;\n"]]}]''')
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
    if rel not in ("public/javascript/telefonia_host.js","public/javascript/telefonia_disponibilidad.js"):
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
