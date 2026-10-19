#!/usr/bin/env bash
# Sólo CSS y JS de disponibilidad. No modifica PBX, PHP ni SQL.
set -euo pipefail
umask 077
MODE=--check
if [ "$#" -gt 0 ]; then MODE="$1"; fi
EXTRA=""
case "$MODE" in
 --check|--apply) [ "$#" -le 1 ] || exit 2;;
 --restore) [ "$#" -eq 2 ] || exit 2; EXTRA="$2";;
 *) echo 'Uso: --check | --apply | --restore RESPALDO' >&2; exit 2;;
esac
command -v python3 >/dev/null || { echo 'Se requiere Python3' >&2; exit 2; }
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT
if [ "$MODE" != --restore ]; then
 if [[ -v IMPE_AVAILABILITY_MIRROR_DIR ]]; then
  for kind in base-js base-css target-js target-css; do cp "$IMPE_AVAILABILITY_MIRROR_DIR/$kind" "$TMP/$kind"; done
 else
  command -v curl >/dev/null || exit 2
  URL='https://raw.githubusercontent.com/davidvaldez050708-dot/SistemaComercialIMPE'
  BASE=dc27b76fcebcb35e36b50817a5b1b956bb502688
  TARGET=0527511db74ec482651bb4a707d0edbb6a8dcdcf
  curl -fsSL --retry 2 "$URL/$BASE/public/javascript/telefonia_disponibilidad.js" -o "$TMP/base-js"
  curl -fsSL --retry 2 "$URL/$BASE/public/css/telefonia_disponibilidad.css" -o "$TMP/base-css"
  curl -fsSL --retry 2 "$URL/$TARGET/public/javascript/telefonia_disponibilidad.js" -o "$TMP/target-js"
  curl -fsSL --retry 2 "$URL/$TARGET/public/css/telefonia_disponibilidad.css" -o "$TMP/target-css"
 fi
fi
python3 - "$MODE" "$TMP" "$EXTRA" <<'PY'
import difflib,hashlib,json,os,shutil,subprocess,sys,tempfile
from datetime import datetime
from pathlib import Path
mode,stage_arg,restore_arg=sys.argv[1:]
stage=Path(stage_arg)
live=Path(os.environ.get('IMPE_AVAILABILITY_LIVE') or Path.home()/'domains/fundacionrededumex.org/public_html')
backups=Path(os.environ.get('IMPE_AVAILABILITY_BACKUPS') or Path.home()/'respaldos-crm')
files=[
 ('public/javascript/telefonia_disponibilidad.js','js','d9394c56a4c2aa1ea21d7398d6df25504f03b65c','0bcfa245a12c90131ba4c90329aa8492985c1e8a'),
 ('public/css/telefonia_disponibilidad.css','css','f502a6716c6bbaeabf000354609177669744c93d','4aeb66fe10e131a7267e5ca75bf2fab8f5bafceb')
]
def fail(s): raise SystemExit('CONFLICTO: '+s+'. Producción intacta si se estaba comprobando.')
def ensure_safe(p):
 if any(a.is_symlink() for a in (p,*p.parents)):fail('Enlace simbólico: '+str(p))
def sha(d):return hashlib.sha256(d).hexdigest()
def blob(d):return hashlib.sha1(b'blob '+str(len(d)).encode()+b'\0'+d).hexdigest()
def atomic(path,data):
 fd,tmp=tempfile.mkstemp(prefix='.avail-ui-',dir=str(path.parent))
 try:
  with os.fdopen(fd,'wb') as h:h.write(data);h.flush();os.fsync(h.fileno())
  os.chmod(tmp,path.stat().st_mode & 0o777);os.replace(tmp,path)
 finally:
  if os.path.exists(tmp):os.unlink(tmp)
ensure_safe(live);ensure_safe(backups)
if not live.is_dir() or not (live/'index.php').is_file():fail('No se encontró public_html del CRM')
if live.resolve() == backups.resolve() or live.resolve() in backups.resolve().parents:fail('Respaldos deben estar fuera de public_html')
paths={name:live/name for name,_,_,_ in files}
for name,p in paths.items():
 ensure_safe(p)
 if not p.is_file():fail('Archivo no encontrado: '+name)
if mode=='--restore':
 folder=Path(restore_arg);ensure_safe(folder)
 if folder.parent.resolve()!=backups.resolve() or not folder.is_dir():fail('Respaldo no autorizado')
 mf=folder/'manifest.json';ensure_safe(mf)
 if not mf.is_file():fail('Falta manifest')
 manifest=json.loads(mf.read_text(encoding='utf-8'))
 to_restore=[]
 for name,_,_,_ in files:
  src=folder/name;ensure_safe(src)
  if not src.is_file():fail('Falta respaldo de '+name)
  orig=src.read_bytes();entry=manifest.get('files',{}).get(name,{})
  if sha(orig)!=entry.get('before') or sha(paths[name].read_bytes())!=entry.get('after'):fail('El archivo cambió: '+name)
  to_restore.append((paths[name],orig))
 for p,d in to_restore:atomic(p,d)
 print('OK: respaldo restaurado, 2 archivos.')
 sys.exit(0)
def prepare(current,old,new,name):
 if current==new:return current,'YA ACTUALIZADO'
 if current==old:return new,'BASE EXACTA'
 a=old.splitlines(keepends=True);b=new.splitlines(keepends=True)
 groups=list(difflib.SequenceMatcher(None,a,b,autojunk=False).get_grouped_opcodes(4))
 if not groups:fail('El parche no contiene diferencias')
 hunks=[]
 for group in groups:
  before=b''.join(a[group[0][1]:group[-1][2]])
  after=b''.join(b[group[0][3]:group[-1][4]])
  if not before or not after:fail('Bloque inválido '+name)
  hunks.append((before,after))
 if all(current.count(after)==1 for _,after in hunks):return current,'YA ACTUALIZADO (PERSONALIZADO)'
 if not all(current.count(before)==1 for before,_ in hunks):fail('Variante Hostinger no compatible de '+name)
 result=current
 for before,after in hunks:
  if result.count(before)!=1:fail('Bloque ambiguo '+name)
  result=result.replace(before,after,1)
 return result,'PARCHE COMPATIBLE'
prepared={}
for name,tag,base_sha,target_sha in files:
 old=(stage/('base-'+tag)).read_bytes();new=(stage/('target-'+tag)).read_bytes()
 if blob(old)!=base_sha or blob(new)!=target_sha:fail('Fuente remota no coincide con SHA aprobado')
 current=paths[name].read_bytes()
 updated,status=prepare(current,old,new,name)
 decoded=updated.decode('utf-8')
 if tag=='js':
  if decoded.count('source.dataset.availabilityState=status;')!=1 or 'incoming.dataset.availabilityBlocked=' not in decoded:fail('Protección visual JS incompleta')
  if shutil.which('node'):
   tmp=stage/'check.js';tmp.write_bytes(updated)
   p=subprocess.run(['node','--check',str(tmp)],capture_output=True,text=True)
   if p.returncode:fail('JS con error de sintaxis: '+p.stderr[:180])
 else:
  if '/* Ajustes visuales de disponibilidad · 2026-10-19. */' not in decoded:fail('Estilos no presentes')
 prepared[name]=(current,updated)
 print(status+': '+name)
if mode=='--check':
 print('OK: revisión compatible. '+str(sum(a!=b for a,b in prepared.values()))+' archivo(s) por publicar. SITIO INTACTO.')
 sys.exit(0)
pending=[(name,a,b) for name,(a,b) in prepared.items() if a!=b]
if not pending:
 print('OK: interfaz ya instalada, sin cambios.')
 sys.exit(0)
backups.mkdir(parents=True,mode=0o700,exist_ok=True);ensure_safe(backups)
folder=Path(tempfile.mkdtemp(prefix='disponibilidad-ui-'+datetime.now().strftime('%Y%m%d-%H%M%S')+'-',dir=str(backups)))
os.chmod(folder,0o700)
manifest={'files':{}}
for name,(before,after) in prepared.items():
 src=folder/name;src.parent.mkdir(parents=True,exist_ok=True)
 shutil.copy2(paths[name],src)
 if sha(src.read_bytes())!=sha(before):fail('El respaldo no coincide: '+name)
 manifest['files'][name]={'before':sha(before),'after':sha(after)}
(folder/'manifest.json').write_text(json.dumps(manifest,indent=2),encoding='utf-8')
for name,(before,after) in prepared.items():
 if sha(paths[name].read_bytes())!=sha(before):fail('El archivo cambió durante la revisión: '+name)
done=[]
try:
 for name,before,after in pending:
  atomic(paths[name],after);done.append((name,before))
  if sha(paths[name].read_bytes())!=sha(after):raise RuntimeError('Error verificando '+name)
except BaseException:
 for name,before in reversed(done):atomic(paths[name],before)
 raise
print('OK: --apply, '+str(len(pending))+' archivo(s). Sin PHP, SQL ni SDK.')
print('Respaldo:',folder)
print('Rollback: bash "$HOME/publicar-disponibilidad-ui.sh" --restore "'+str(folder)+'"')
PY
