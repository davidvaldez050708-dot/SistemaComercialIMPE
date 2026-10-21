#!/usr/bin/env bash
# Parche PINNED SHA256: shell Hostinger 45519 + tres archivos SIP/ventas.
# No modifica SQL, PHP, credenciales ni archivos ajenos.
set -euo pipefail
umask 077
IMPE_PUBLISHER_PATH="$(realpath "$0")" python3 - "$@" <<'PY'
import hashlib, json, os, sys, shutil, tempfile, datetime, re
from pathlib import Path

TAG = "impe-sip-fin-pbx-20261021-hostinger45519-v3"
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
    if filename == "public/javascript/telefonia_shell.js" and len(pairs) == 1:
        old,new = pairs[0]
        if content.count(new) == 1:
            return raw
        if content.count(old) != 1:
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
            method = method.replace(original_update,active_branch+original_update,1)
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
