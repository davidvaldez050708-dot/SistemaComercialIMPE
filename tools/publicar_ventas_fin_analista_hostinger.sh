#!/usr/bin/env bash
# Ventas: mismo fin de llamada que Analista. Parche selectivo, reversible y compatible con Python 3.6.
set -euo pipefail
umask 077
python3 - "$@" <<'PY'
import os,sys,json,hashlib,tempfile,stat
from pathlib import Path
PAIRS=json.loads(r'''[[" // El SDK cerró esta conversación, pero los eventos PBX pueden llegar más\n // tarde. Permitir abrir YA el formulario de esa transferencia concreta;\n // el backend sigue siendo quien autoriza el guardado definitivo.\n const sipFinished=state=>Boolean(state&&!state.active&&\n  ['incoming-finished','finished'].includes(state.phase)&&\n  state.context?.sdkEnded===true&&\n  (state.context?.sdkConnected||state.context?.pbxAnswered||Number(state.answeredAtMs)>0));\n const provisional=state=>{\n  if(!sipFinished(state))return null;"," // El teléfono compartido (Analista/Ventas) sólo publica una fase final\n // tras END SIP verificado o cierre propio de PBX. No exigir sdkEnded cuando\n // finalizarEntrante() ya publicó incoming-finished desde Zadarma.\n // Esto habilita la captura humana, NO acredita el cierre en BD.\n const phoneFinished=state=>Boolean(state&&!state.active&&\n  ['incoming','outgoing'].includes(state.direction)&&\n  ['incoming-finished','finished'].includes(state.phase));\n const provisional=state=>{\n  if(!phoneFinished(state))return null;"],["!(sipFinished(state)&&provisional(state)?.ref===task.ref)","!(phoneFinished(state)&&provisional(state)?.ref===task.ref)"],["if(sipFinished(state)&&(!metadata?.finalizada||metadata.pbx_call_id!==task.ref))","if(phoneFinished(state)&&(!metadata?.finalizada||metadata.pbx_call_id!==task.ref))"],["  button.hidden=active||form||(!finished&&!pending.length&&!instant);\n  button.disabled=!canOpen;\n  button.textContent=canOpen?'Registrar resultado':'Conciliando llamada';","  // Sin referencia de esta sesión, no usar el primer pendiente histórico.\n  button.hidden=active||form||(!finished&&!pending.length&&!instant)||(finished&&!task);\n  button.disabled=!canOpen;\n  button.textContent='Registrar resultado';"]]''')
REL='public/javascript/telefonia_ventas_shell.js'
def error(msg):
    sys.stderr.write('CONFLICTO: '+msg+'\n')
    sys.exit(2)
if len(sys.argv)<2 or sys.argv[1] not in ('--check','--apply','--restore'):
    sys.stderr.write('Uso: IMPE_CRM_PUBLIC=/ruta/public_html bash script --check|--apply|--restore [respaldo]\n')
    sys.exit(1)
mode=sys.argv[1]
root=os.environ.get('IMPE_CRM_PUBLIC','')
if not root: error('Falta IMPE_CRM_PUBLIC.')
root=Path(root).resolve()
if not (root/'index.php').is_file(): error('La ruta no es la raíz del CRM.')
path=root/REL
if path.is_symlink() or not path.is_file() or root not in path.resolve().parents: error('Archivo ausente o enlace: '+REL)
backup_root=Path(os.environ.get('IMPE_CRM_BACKUPS',str(Path.home()/'impe-ventas-resultados-backups'))).resolve()
if backup_root==root or backup_root in root.parents or root in backup_root.parents:
    error('El directorio de respaldos no debe coincidir o contener el sitio, ni estar dentro de él.')
def sha(data): return hashlib.sha256(data).hexdigest()
def atomic(data,modebits):
    fd,temp=tempfile.mkstemp(prefix='.impe-',dir=str(path.parent))
    try:
        os.fchmod(fd,modebits)
        with os.fdopen(fd,'wb') as out:
            out.write(data)
            out.flush()
            os.fsync(out.fileno())
        os.replace(temp,str(path))
    finally:
        if os.path.exists(temp): os.unlink(temp)
if mode=='--restore':
    if len(sys.argv)!=3: error('Indica la ruta exacta del respaldo.')
    backup=Path(sys.argv[2]).resolve()
    if backup_root not in backup.parents or backup.is_symlink(): error('Respaldo fuera del directorio privado.')
    manifest=backup/'manifest.json'
    datafile=backup/'ventas_shell.bak'
    if not manifest.is_file() or not datafile.is_file(): error('Respaldo incompleto.')
    info=json.loads(manifest.read_text(encoding='utf-8'))
    if info.get('path')!=REL: error('Manifiesto de otra ruta.')
    original=datafile.read_bytes()
    current=path.read_bytes()
    if sha(original)!=info.get('before'): error('El respaldo fue modificado.')
    if sha(current) not in (info.get('after'),info.get('before')): error('El sitio fue editado después: no sobrescribir.')
    if sha(current)==info.get('after'): atomic(original,info['mode'])
    print('OK: restauración verificada sin tocar otros módulos.')
    sys.exit(0)
if len(sys.argv)!=2: error('Argumentos no admitidos.')
original=path.read_bytes()
try: decoded=original.decode('utf-8')
except UnicodeDecodeError: error('No es UTF-8.')
crlf=b'\r\n' in original
normalized=decoded.replace('\r\n','\n')
before=[];after=[]
for old,new in PAIRS:
    count_old=normalized.count(old)
    count_new=normalized.count(new)
    if count_old==1 and count_new==0: before.append((old,new))
    elif count_old==0 and count_new==1: after.append((old,new))
    else: error('Bloque desconocido o duplicado de telefonía; no aplicar automáticamente.')
if before and after: error('Versión parcialmente modificada; se requiere revisión, no reemplazo parcial.')
print(REL+': '+('pendiente' if before else 'actualizado'))
print('Archivos a modificar:',1 if before else 0)
if mode=='--check':
    print('OK: verificación de solo lectura; ninguna modificación.')
    sys.exit(0)
if not before:
    print('OK: ya está actualizado.')
    sys.exit(0)
modified=normalized
for old,new in PAIRS: modified=modified.replace(old,new,1)
new_bytes=modified.replace('\n','\r\n').encode('utf-8') if crlf else modified.encode('utf-8')
backup_root.mkdir(parents=True,exist_ok=True,mode=0o700)
os.chmod(str(backup_root),0o700)
backup=Path(tempfile.mkdtemp(prefix='fin-analista-ventas-',dir=str(backup_root)))
os.chmod(str(backup),0o700)
datafile=backup/'ventas_shell.bak'
datafile.write_bytes(original)
os.chmod(str(datafile),0o600)
manifest=backup/'manifest.json'
manifest.write_text(json.dumps(dict(path=REL,before=sha(original),after=sha(new_bytes),
    mode=stat.S_IMODE(path.stat().st_mode)),indent=2),encoding='utf-8')
os.chmod(str(manifest),0o600)
if path.read_bytes()!=original: error('El archivo fue modificado durante la comprobación.')
atomic(new_bytes,stat.S_IMODE(path.stat().st_mode))
print('OK: corrección aplicada. Respaldo:',str(backup))
print('Sin SQL, sin cambios en Zadarma, Analista, Marketing o grabaciones.')
PY
