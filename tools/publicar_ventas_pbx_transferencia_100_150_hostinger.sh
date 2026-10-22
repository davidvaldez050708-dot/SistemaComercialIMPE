#!/usr/bin/env bash
# Ventas: reparar criterios PBX 100→150 de dos servicios sin tocar recepcion ni Analista.
# Compatible Python 3.6 / Hostinger CloudLinux. --check, --apply, --restore.
set -euo pipefail
umask 077
python3 - "$@" <<'PY'
import json,os,sys,hashlib,stat,tempfile
from pathlib import Path
SPECS=json.loads(r'''[{"path":"app/services/ZadarmaWebhookEventStoreService.php","from":"                    ($salienteTransferida && $tipo === 'NOTIFY_OUT_END' && $internal === $origenTransferencia) ||","to":"                    // OUT_END del origen que llegó antes de ANSWER del receptor\n                    // cierra el tramo 100, NO una conversación futura del 150.\n                    ($salienteTransferida && $tipo === 'NOTIFY_OUT_END' &&\n                        $internal === $origenTransferencia && $respuesta !== null) ||"},{"path":"app/services/TelefoniaTransferenciaOperacionService.php","from":"           EXISTS(SELECT 1 FROM telefonia_zadarma_eventos e WHERE e.pbx_call_id=BINARY o.referencia AND e.id>o.evento_desde AND\n            ((e.evento='NOTIFY_END' AND (e.internal=BINARY o.destino_extension OR (JSON_VALID(e.payload_json) AND JSON_UNQUOTE(JSON_EXTRACT(e.payload_json,'$.last_internal'))=BINARY o.destino_extension))) OR e.evento='NOTIFY_OUT_END')) AS ended","to":"           EXISTS(SELECT 1 FROM telefonia_zadarma_eventos e\n            JOIN telefonia_zadarma_eventos a ON a.pbx_call_id=BINARY o.referencia\n                AND a.evento='NOTIFY_ANSWER' AND a.internal=BINARY o.destino_extension\n                AND a.id>o.evento_desde AND e.id>a.id\n            WHERE e.pbx_call_id=BINARY o.referencia\n              AND ((e.evento='NOTIFY_END' AND\n                  (e.internal=BINARY o.destino_extension OR\n                   (JSON_VALID(e.payload_json) AND JSON_UNQUOTE(JSON_EXTRACT(e.payload_json,'$.last_internal'))=BINARY o.destino_extension)))\n                OR (e.evento='NOTIFY_OUT_END' AND\n                  (e.internal=BINARY o.destino_extension OR\n                   (JSON_VALID(e.payload_json) AND JSON_UNQUOTE(JSON_EXTRACT(e.payload_json,'$.last_internal'))=BINARY o.destino_extension) OR\n                   (e.internal=BINARY o.origen_extension AND\n                    (NOT JSON_VALID(e.payload_json) OR\n                     COALESCE(JSON_UNQUOTE(JSON_EXTRACT(e.payload_json,'$.last_internal')),'') IN ('',o.origen_extension))))))) AS ended"}]''')
def abort(message):
    sys.stderr.write("CONFLICTO: "+message+"\n")
    sys.exit(2)
if len(sys.argv)<2 or sys.argv[1] not in ('--check','--apply','--restore'):
    sys.stderr.write('Uso: IMPE_CRM_PUBLIC=... bash publicar_ventas_pbx.sh --check|--apply|--restore [respaldo]\n')
    sys.exit(1)
mode=sys.argv[1]
rt=os.environ.get('IMPE_CRM_PUBLIC','')
if not rt: abort('Define IMPE_CRM_PUBLIC.')
root=Path(rt).resolve()
if not (root/'index.php').is_file(): abort('No es la raiz del CRM.')
backup_root=Path(os.environ.get('IMPE_CRM_BACKUPS',str(Path.home()/'impe-ventas-resultados-backups'))).resolve()
if backup_root==root or backup_root in root.parents or root in backup_root.parents:
    abort('Respaldo debe quedar fuera de la raiz y su arbol.')
def sha(data): return hashlib.sha256(data).hexdigest()
def path_for(rel):
    p=root/rel
    if p.is_symlink() or not p.is_file() or root not in p.resolve().parents:
        abort('Archivo inexistente, symlink o fuera de CRM: '+rel)
    return p
def write_atomic(p,data,permission):
    fd,tmp=tempfile.mkstemp(prefix='.impe-pbx-',dir=str(p.parent))
    try:
        os.fchmod(fd,permission)
        with os.fdopen(fd,'wb') as target:
            target.write(data)
            target.flush()
            os.fsync(target.fileno())
        os.replace(tmp,str(p))
    finally:
        if os.path.exists(tmp): os.unlink(tmp)
if mode=='--restore':
    if len(sys.argv)!=3: abort('Indica ruta del respaldo.')
    folder=Path(sys.argv[2]).resolve()
    if backup_root not in folder.parents or folder.is_symlink():
        abort('Respaldo no autorizado.')
    manifest=folder/'manifest.json'
    if not manifest.is_file(): abort('Manifiesto ausente.')
    entries=json.loads(manifest.read_text(encoding='utf-8'))
    if len(entries)!=len(SPECS) or set(e['path'] for e in entries)!=set(x['path'] for x in SPECS):
        abort('Manifiesto no corresponde a este publicador.')
    planned=[]
    for i,e in enumerate(entries):
        p=path_for(e['path'])
        old=(folder/e['backup']).read_bytes()
        current=p.read_bytes()
        if sha(old)!=e['original_sha256']: abort('Respaldo alterado: '+e['path'])
        if sha(current) not in (e['applied_sha256'],e['original_sha256']):
            abort('Edicion posterior detectada: '+e['path'])
        planned.append((p,old,e['mode'],sha(current)==e['applied_sha256']))
    for p,old,permission,needed in planned:
        if needed: write_atomic(p,old,permission)
    print('OK: restaurados los servicios PBX, sin SQL.')
    sys.exit(0)
if len(sys.argv)!=2: abort('Parametros adicionales no permitidos.')
actions=[]
for e in SPECS:
    p=path_for(e['path'])
    raw=p.read_bytes()
    try: content=raw.decode('utf-8')
    except UnicodeDecodeError: abort('PHP no UTF8: '+e['path'])
    crlf=b'\r\n' in raw
    normalized=content.replace('\r\n','\n')
    old=e['from'];new=e['to']
    if normalized.count(old)==1 and normalized.count(new)==0:
        updated=normalized.replace(old,new,1)
        kind='pendiente'
    elif normalized.count(old)==0 and normalized.count(new)==1:
        updated=normalized
        kind='actualizado'
    else:
        abort('Version no prevista; no reemplazar personalizaciones: '+e['path'])
    target=(updated.replace('\n','\r\n') if crlf else updated).encode('utf-8')
    actions.append(dict(path=e['path'],file=p,old=raw,new=target,mode=stat.S_IMODE(p.stat().st_mode),status=kind))
for e in actions: print(e['path']+': '+e['status'])
count=sum(1 for e in actions if e['status']=='pendiente')
print('Archivos a modificar:',count)
if mode=='--check':
    print('OK: verificacion de solo lectura. No se aplicaron cambios.')
    sys.exit(0)
if not count:
    print('OK: ya actualizado. No se modifico nada.')
    sys.exit(0)
backup_root.mkdir(mode=0o700,parents=True,exist_ok=True)
os.chmod(str(backup_root),0o700)
folder=Path(tempfile.mkdtemp(prefix='ventas-pbx-100-150-',dir=str(backup_root)))
os.chmod(str(folder),0o700)
entries=[]
for i,e in enumerate(actions):
    fname='file-%02d.bak'%i
    fp=folder/fname
    fp.write_bytes(e['old'])
    os.chmod(str(fp),0o600)
    entries.append(dict(path=e['path'],backup=fname,mode=e['mode'],
        original_sha256=sha(e['old']),applied_sha256=sha(e['new'])))
manifest=folder/'manifest.json'
manifest.write_text(json.dumps(entries,indent=2),encoding='utf-8')
os.chmod(str(manifest),0o600)
written=[]
try:
    for e in actions:
        if e['status']!='pendiente':continue
        if e['file'].read_bytes()!=e['old']: abort('Edicion concurrente: '+e['path'])
        write_atomic(e['file'],e['new'],e['mode'])
        written.append(e)
except BaseException:
    for e in reversed(written):
        if sha(e['file'].read_bytes())==sha(e['new']):write_atomic(e['file'],e['old'],e['mode'])
    raise
print('OK: correccion PBX publicada. Respaldo:',str(folder))
print('No se modifico BD, webhooks recibidos, grabaciones ni extensiones.')
PY
