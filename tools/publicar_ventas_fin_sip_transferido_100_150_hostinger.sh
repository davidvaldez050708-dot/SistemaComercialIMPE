#!/usr/bin/env bash
# Ventas: END SIP transferido 100 -> 150. Nunca modifica Analista, PBX ni BD.
set -euo pipefail
umask 077
python3 - "$@" <<'PY'
import json,sys,os,hashlib,subprocess,shutil,tempfile,stat
from pathlib import Path
SPECS=json.loads(r'''[{"path":"app/views/telefonia/host.php","pairs":[["            'permiteSalientes' => !empty($asignacionTelefonica['permite_salientes']),\n            'permiteEntrantes' => !empty($asignacionTelefonica['permite_entrantes']),\n            // Analista y Ventas autorizados comparten el observador embebido.\n            // Marketing continúa usando exclusivamente eventos PBX.\n            'analystSdkIncoming' => $receptorHostSdk,\n            'permiteTransferir' =>\n                !empty($asignacionTelefonica['permite_transferir']) &&\n                tienePermiso('telefonia.transferir')\n        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;\n    </script>","            'permiteSalientes' => !empty($asignacionTelefonica['permite_salientes']),\n            'permiteEntrantes' => !empty($asignacionTelefonica['permite_entrantes']),\n            // Analista y Ventas autorizados comparten el observador embebido.\n            // Marketing continúa usando exclusivamente eventos PBX.\n            'analystSdkIncoming' => $receptorHostSdk,\n            // Identificar únicamente el flujo de Ventas, sin cambiar Analista.\n            'salesSdkIncoming' => $asesorHostSdk,\n            'permiteTransferir' =>\n                !empty($asignacionTelefonica['permite_transferir']) &&\n                tienePermiso('telefonia.transferir')\n        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;\n    </script>"]]},{"path":"public/javascript/telefonia_host.js","pairs":[["                            String(sipSession.id || '') === observedId &&\n                            sipSession.isEnded?.())\n                    ));\n                    const sipAlive = sipSession?.direction === 'incoming' &&\n                        !sipSession.isEnded?.();\n                    const sdkOnlyMatches = sdkOnly &&\n                        (!observedId || (boundId && observedId === boundId) ||\n                            (sipSession?.direction === 'incoming' &&\n                                String(sipSession.id || '') === observedId &&\n                                sipSession.isEnded?.()));","                            String(sipSession.id || '') === observedId &&\n                            sipSession.isEnded?.())\n                    ));\n                    const sipAlive = sipSession?.direction === 'incoming' &&\n                        !sipSession.isEnded?.();\n                    // JsSIP puede emitir ended/failed antes de que isEnded()\n                    // cambie o antes de soltar webCallSession. Para Ventas,\n                    // el evento terminal de la sesión SIP EXACTA prevalece\n                    // sobre ese puntero transitoriamente activo.\n                    // Si el teléfono ya tiene OTRO diálogo vivo, no cerrarlo.\n                    const ownSalesTerminal = Boolean(config.salesSdkIncoming &&\n                        evidence.sessionEnded && observedId && boundId === observedId &&\n                        state.context?.transferReference &&\n                        (!sipAlive || String(sipSession?.id || '') === observedId));\n                    const sdkOnlyMatches = sdkOnly &&\n                        (!observedId || (boundId && observedId === boundId) ||\n                            (sipSession?.direction === 'incoming' &&\n                                String(sipSession.id || '') === observedId &&\n                                sipSession.isEnded?.()));"],["                            (sipSession?.direction === 'incoming' &&\n                                String(sipSession.id || '') === observedId &&\n                                sipSession.isEnded?.()));\n                    if ((evidence.sessionEnded || evidence.pushEnded) &&\n                        state.active && state.direction === 'incoming' &&\n                        !sipAlive && (sdkOnlyMatches || (attachedPbx &&\n                            (sameSip || (evidence.pushEnded && !observedId &&\n                                ['incoming-ringing','answering','finishing','rejecting'].includes(state.phase)))))) {\n                        const ref = String(state.pbxCallId || state.context?.transferReference || '');\n                        const wasAnswered = Boolean(state.context?.sdkConnected ||\n                            state.context?.pbxAnswered || answeredAtMs > 0);","                            (sipSession?.direction === 'incoming' &&\n                                String(sipSession.id || '') === observedId &&\n                                sipSession.isEnded?.()));\n                    if ((evidence.sessionEnded || evidence.pushEnded) &&\n                        state.active && state.direction === 'incoming' &&\n                        (!sipAlive || ownSalesTerminal) && (sdkOnlyMatches || (attachedPbx &&\n                            (sameSip || (evidence.pushEnded && !observedId &&\n                                ['incoming-ringing','answering','finishing','rejecting'].includes(state.phase)))))) {\n                        const ref = String(state.pbxCallId || state.context?.transferReference || '');\n                        const wasAnswered = Boolean(state.context?.sdkConnected ||\n                            state.context?.pbxAnswered || answeredAtMs > 0);"],["        external:(op,token,connected)=>{\n            if(declinedInvitation(String(op.referencia||'')))return;\n            answeredAtMs=connected?(state.answeredAtMs||Date.now()):0;\n            publish({callToken:token,pbxCallId:'',destination:op.numero,phase:connected?'in-progress':'incoming-ringing',status:connected?'in-progress':'incoming-ringing',answeredAtMs,duration:0,context:{type:'SDK_INCOMING',sdkConnected:connected,sdkInviteId:state.context?.sdkInviteId,sdkSessionId:state.callToken,transferFrom:op.origen_extension,transferReference:op.referencia,transferOperation:op.id},message:'Recepción entregó la llamada. Ya estás hablando con la persona.'});\n        },\n        consultEnded:op=>{\n            if(!state.active)return;\n            if(op.modo==='DIRECTA'){\n                const answered=Boolean(state.context?.sdkConnected||state.context?.pbxAnswered||answeredAtMs>0);\n                publish({active:true,phase:answered?'recovering':'finishing',status:answered?'recovering':'ending',context:{...state.context,transferPendingReceiver:false,transferReference:op.referencia},message:answered?'El audio del navegador se interrumpió. Comprobando si la llamada sigue activa en la centralita.':'Intento sin respuesta confirmada. Comprobando cierre en la centralita.'});\n                void pollIncoming();return;\n            }\n            answeredAtMs=0;publish({active:false,phase:'incoming-finished',status:'consultation-ended',duration:0,message:'Consulta de transferencia finalizada.'});\n        }","        external:(op,token,connected)=>{\n            if(declinedInvitation(String(op.referencia||'')))return;\n            answeredAtMs=connected?(state.answeredAtMs||Date.now()):0;\n            publish({callToken:token,pbxCallId:'',destination:op.numero,phase:connected?'in-progress':'incoming-ringing',status:connected?'in-progress':'incoming-ringing',answeredAtMs,duration:0,context:{type:'SDK_INCOMING',sdkConnected:connected,sdkInviteId:state.context?.sdkInviteId,sdkSessionId:state.callToken,transferFrom:op.origen_extension,transferReference:op.referencia,transferOperation:op.id},message:'Recepción entregó la llamada. Ya estás hablando con la persona.'});\n        },\n        receiverEnded:(op,token,endedSession)=>{\n            // ENTREGADA sólo la acredita la PBX para la extensión propia.\n            // No procesar eventos de la sesión antigua o de otra llamada.\n            if(!config.salesSdkIncoming||!state.active||state.direction!=='incoming'||\n                !endedSession?.isEnded?.()||\n                op.estado!=='ENTREGADA'||!state.context?.sdkConnected||\n                String(token||'')!==String(state.callToken||'')||\n                String(state.context?.transferReference||'')!==String(op.referencia||'')||\n                String(state.context?.transferOperation||'')!==String(op.id||''))return;\n            const live=window.zdrmWebPhone?.webCallSession;\n            if(live&&live!==endedSession&&!live.isEnded?.())return;\n            const ref=String(op.referencia||''),duration=currentDuration();\n            answeredAtMs=0;retirePbxCall(ref);\n            publish({active:false,phase:'incoming-finished',status:'ended-unconfirmed',\n                pbxCallId:ref,duration,\n                context:{...state.context,sdkEnded:true,transferPendingReceiver:false},\n                message:'La sesión SIP terminó. Evidencia PBX pendiente.'});\n            ocultarHostEntrante();marcarPestanaEntrante('');\n        },\n        consultEnded:op=>{\n            if(!state.active)return;\n            if(op.modo==='DIRECTA'){\n                const answered=Boolean(state.context?.sdkConnected||state.context?.pbxAnswered||answeredAtMs>0);\n                const session=window.zdrmWebPhone?.webCallSession;\n                // El END de la consulta DIRECTA se comprobó en transferFlow\n                // contra la RTCSession del receptor. Si Zadarma ya acreditó\n                // ENTREGADA y no existe otro diálogo SIP vivo, finalizar\n                // inmediatamente el teléfono local. El END PBX y las\n                // grabaciones continúan conciliándose por separado.\n                const ownDelivered=Boolean(config.salesSdkIncoming &&\n                    op.estado==='ENTREGADA' && answered &&\n                    state.direction==='incoming' &&\n                    String(state.context?.transferOperation||'')===String(op.id||'') &&\n                    String(state.context?.transferReference||'')===String(op.referencia||'') &&\n                    (!session || session.isEnded?.()));\n                if(ownDelivered){\n                    const ref=String(op.referencia||''),duration=currentDuration();\n                    answeredAtMs=0;\n                    retirePbxCall(ref);\n                    publish({active:false,phase:'incoming-finished',status:'ended-unconfirmed',\n                        pbxCallId:ref,duration,\n                        context:{...state.context,sdkEnded:true,transferPendingReceiver:false},\n                        message:'La sesión SIP terminó. La evidencia PBX se conciliará por separado.'});\n                    ocultarHostEntrante();marcarPestanaEntrante('');\n                    return;\n                }\n                publish({active:true,phase:answered?'recovering':'finishing',status:answered?'recovering':'ending',context:{...state.context,transferPendingReceiver:false,transferReference:op.referencia},message:answered?'El audio del navegador se interrumpió. Comprobando si la llamada sigue activa en la centralita.':'Intento sin respuesta confirmada. Comprobando cierre en la centralita.'});\n                void pollIncoming();return;\n            }\n            answeredAtMs=0;publish({active:false,phase:'incoming-finished',status:'consultation-ended',duration:0,message:'Consulta de transferencia finalizada.'});\n        }"]]},{"path":"public/javascript/telefonia_transferencia_runtime.js","pairs":[["     catch(e){api.release({...tracked.op,estado:'FALLIDA'});throw e;}\n     return;\n    }\n    if(!tracked.source && session?.isEnded?.() && !endedReported){\n     endedReported=true;try{apply(await request(tracked.op.id,'TERMINADA'));}catch(e){endedReported=false;throw e;}\n     if(!external)api.consultEnded(tracked.op);reset();return;\n    }\n    if(!tracked.source && tracked.op.modo==='CONSULTADA' && !external && session?.isEstablished?.() && !atendida){\n     apply(await request(tracked.op.id,'ATENDIDA'));atendida=true;\n    }\n   }catch(e){","     catch(e){api.release({...tracked.op,estado:'FALLIDA'});throw e;}\n     return;\n    }\n    if(!tracked.source && session?.isEnded?.() && !endedReported){\n     endedReported=true;try{apply(await request(tracked.op.id,'TERMINADA'));}catch(e){endedReported=false;throw e;}\n     if(!external)api.consultEnded(tracked.op);\n     else api.receiverEnded?.(tracked.op,tracked.token,session);\n     reset();return;\n    }\n    if(!tracked.source && tracked.op.modo==='CONSULTADA' && !external && session?.isEstablished?.() && !atendida){\n     apply(await request(tracked.op.id,'ATENDIDA'));atendida=true;\n    }\n   }catch(e){"]]}]''')
def fail(msg):
    sys.stderr.write("CONFLICTO: "+msg+"\n");sys.exit(2)
if len(sys.argv)<2 or sys.argv[1] not in ('--check','--apply','--restore'):
    sys.stderr.write("Uso: IMPE_CRM_PUBLIC=/ruta/public_html bash script --check|--apply|--restore [respaldo]\n");sys.exit(1)
cmd=sys.argv[1]
live_raw=os.environ.get('IMPE_CRM_PUBLIC','')
if not live_raw:fail('Indica IMPE_CRM_PUBLIC.')
root=Path(live_raw).resolve()
if not (root/'index.php').is_file():fail('No se reconoce la raiz del CRM.')
backups=Path(os.environ.get('IMPE_CRM_BACKUPS',str(Path.home()/'impe-ventas-resultados-backups'))).resolve()
if backups==root or backups in root.parents or root in backups.parents:fail('Respaldos fuera de public_html.')
def sha(data):return hashlib.sha256(data).hexdigest()
def read_target(path):
    file=root/path
    if file.is_symlink() or not file.is_file() or root not in file.resolve().parents:
        fail('Ruta no reconocida: '+path)
    return file
def atomic(file,data,mode):
    fd,tmp=tempfile.mkstemp(prefix='.impe-ventas-',dir=str(file.parent))
    try:
        os.fchmod(fd,mode)
        with os.fdopen(fd,'wb') as f:
            f.write(data);f.flush();os.fsync(f.fileno())
        os.replace(tmp,str(file))
    finally:
        if os.path.exists(tmp):os.unlink(tmp)
def lint(path,data):
    ext='.php' if path.endswith('.php') else '.js'
    program='php' if ext=='.php' else 'node'
    if not shutil.which(program):
        if program=='php':fail('No esta php para validar sintaxis.')
        print('AVISO: Node.js no disponible en Hostinger; sintaxis JS validada en CI.')
        return
    fd,location=tempfile.mkstemp(suffix=ext)
    try:
        with os.fdopen(fd,'wb') as f:f.write(data)
        proc=subprocess.run([program,'-l' if ext=='.php' else '--check',location],
            stdout=subprocess.PIPE,stderr=subprocess.PIPE)
        if proc.returncode:fail('Error de sintaxis en '+path+': '+
            (proc.stdout+proc.stderr).decode('utf-8','replace')[-600:])
    finally:
        if os.path.exists(location):os.unlink(location)
if cmd=='--restore':
    if len(sys.argv)!=3:fail('Proporciona ruta de respaldo.')
    folder=Path(sys.argv[2]).resolve()
    if backups not in folder.parents or folder.is_symlink():fail('Respaldo no autorizado.')
    manifest=folder/'manifest.json'
    if not manifest.is_file():fail('Falta manifiesto del respaldo.')
    rows=json.loads(manifest.read_text(encoding='utf-8'))
    if len(rows)!=len(SPECS) or set(x['path'] for x in rows)!=set(s['path'] for s in SPECS):
        fail('El respaldo pertenece a otra publicacion.')
    work=[]
    for item in rows:
        file=read_target(item['path'])
        original=(folder/item['backup']).read_bytes()
        actual=file.read_bytes()
        if sha(original)!=item['old_sha']:fail('Respaldo alterado: '+item['path'])
        if sha(actual) not in (item['old_sha'],item['new_sha']):
            fail('Archivo modificado tras la publicacion: '+item['path'])
        work.append((file,original,item['mode'],sha(actual)==item['new_sha']))
    for file,original,mode,need in work:
        if need:atomic(file,original,mode)
    print('OK: restauracion verificada; sin cambios de BD ni grabaciones.')
    sys.exit(0)
if len(sys.argv)!=2:fail('Argumentos no permitidos.')
plan=[]
for item in SPECS:
    path=item['path'];file=read_target(path)
    original=file.read_bytes()
    try:source=original.decode('utf-8')
    except UnicodeDecodeError:fail('Archivo no UTF-8: '+path)
    if b'\r\n' in original and original.count(b'\r\n')!=original.count(b'\n'):
        fail('Finales de linea mixtos: '+path)
    crlf=b'\r\n' in original
    source=source.replace('\r\n','\n')
    latest=source
    old_count=0;new_count=0
    for old,new in item['pairs']:
        # Comprobar contra el bloque completo en el estado original; nunca
        # aplicar cambios parciales ni sobrescribir una variante ajena.
        has_old=source.count(old);has_new=source.count(new)
        if has_old==1 and has_new==0:
            latest=latest.replace(old,new,1);old_count+=1
        elif has_old==0 and has_new==1:new_count+=1
        else:fail('Variante incompatible o fragmento duplicado: '+path)
    if old_count and new_count:fail('Parche a medias: '+path)
    target=(latest.replace('\n','\r\n') if crlf else latest).encode('utf-8')
    state='pendiente' if old_count else 'actualizado'
    if old_count:lint(path,target)
    plan.append(dict(path=path,file=file,original=original,target=target,
        mode=stat.S_IMODE(file.stat().st_mode),state=state))
for item in plan:print(item['path']+': '+item['state'])
todo=sum(x['state']=='pendiente' for x in plan)
print('Archivos a modificar:',todo)
if cmd=='--check':
    print('OK: verificacion de solo lectura. No se modificaron archivos.')
    sys.exit(0)
if not todo:
    print('OK: version instalada, sin modificaciones.')
    sys.exit(0)
backups.mkdir(parents=True,exist_ok=True,mode=0o700)
os.chmod(str(backups),0o700)
folder=Path(tempfile.mkdtemp(prefix='ventas-fin-sip-100-150-',dir=str(backups)))
os.chmod(str(folder),0o700)
record=[]
for idx,item in enumerate(plan):
    backup_name='%02d.bak'%idx
    p=folder/backup_name
    p.write_bytes(item['original']);os.chmod(str(p),0o600)
    record.append(dict(path=item['path'],backup=backup_name,
        old_sha=sha(item['original']),new_sha=sha(item['target']),mode=item['mode']))
manifest=folder/'manifest.json'
manifest.write_text(json.dumps(record,indent=2),encoding='utf-8')
os.chmod(str(manifest),0o600)
changed=[]
try:
    for item in plan:
        if item['state']!='pendiente':continue
        if item['file'].read_bytes()!=item['original']:fail('Archivo editado concurrentemente: '+item['path'])
        atomic(item['file'],item['target'],item['mode']);changed.append(item)
except BaseException:
    for item in reversed(changed):
        if sha(item['file'].read_bytes())==sha(item['target']):
            atomic(item['file'],item['original'],item['mode'])
    raise
print('OK: fin SIP comercial publicado. Respaldo:',str(folder))
print('No se modifico BD, grabaciones, eventos PBX ni servicios de Analista.')
PY
