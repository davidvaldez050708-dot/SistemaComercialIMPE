#!/usr/bin/env bash
# Publica únicamente el aviso WebAudio de disponibilidad. Sin PHP, SQL ni Zadarma.
# --check no toca producción; --apply respalda; --restore RUTA revierte.
set -euo pipefail
umask 077
MODE=--check
if [ "$#" -gt 0 ]; then MODE="$1"; fi
RESTORE=""
case "$MODE" in
 --check|--apply) [ "$#" -le 1 ] || exit 2;;
 --restore) [ "$#" -eq 2 ] || { echo 'Indica la carpeta de respaldo' >&2; exit 2; }; RESTORE="$2";;
 *) echo 'Uso: --check | --apply | --restore RUTA_RESPALDO' >&2; exit 2;;
esac
command -v python3 >/dev/null || { echo 'ERROR: Falta python3' >&2; exit 2; }
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT
if [ "$MODE" != --restore ]; then
 if [ -n "$(printenv IMPE_SOUND_MIRROR 2>/dev/null || true)" ]; then
  cp "$IMPE_SOUND_MIRROR/base.js" "$TMP/base.js"
  cp "$IMPE_SOUND_MIRROR/target.js" "$TMP/target.js"
 else
  command -v curl >/dev/null || { echo 'ERROR: Falta curl' >&2; exit 2; }
  URL=https://raw.githubusercontent.com/davidvaldez050708-dot/SistemaComercialIMPE
  curl -fsSL --retry 2 "$URL/042b89e65711ed8dcf9577a0f8163a86669c86c4/public/javascript/telefonia_disponibilidad.js" -o "$TMP/base.js"
  curl -fsSL --retry 2 "$URL/554b1f566886b1e30c775728b4a399f00fb6d766/public/javascript/telefonia_disponibilidad.js" -o "$TMP/target.js"
 fi
fi
python3 - "$MODE" "$TMP" "$RESTORE" <<'PY'
import difflib,hashlib,json,os,shutil,subprocess,sys,tempfile
from datetime import datetime
from pathlib import Path

mode,stage_path,restore_path=sys.argv[1:]
stage=Path(stage_path)
live=Path(os.environ.get('IMPE_SOUND_LIVE') or Path.home()/'domains/fundacionrededumex.org/public_html')
backups=Path(os.environ.get('IMPE_SOUND_BACKUPS') or Path.home()/'respaldos-crm')
name='public/javascript/telefonia_disponibilidad.js'
file=live/name
base_sha='0bcfa245a12c90131ba4c90329aa8492985c1e8a'
target_sha='6ab1ddf7ef6b03fc4418283d876deede195a13bf'

def fail(reason):
 raise SystemExit('CONFLICTO: '+reason+'. Sin sobrescribir la versión de Hostinger.')
def sha(data):return hashlib.sha256(data).hexdigest()
def gitblob(data):return hashlib.sha1(b'blob '+str(len(data)).encode()+b'\0'+data).hexdigest()
def no_symlink(p):
 if any(q.is_symlink() for q in (p,*p.parents)):fail('Enlace simbólico no permitido: '+str(p))
def atomic(path,data):
 fd,tmp=tempfile.mkstemp(prefix='.impe-aviso-',dir=str(path.parent))
 try:
  with os.fdopen(fd,'wb') as f:f.write(data);f.flush();os.fsync(f.fileno())
  os.chmod(tmp,path.stat().st_mode & 0o777)
  os.replace(tmp,path)
 finally:
  if os.path.exists(tmp):os.unlink(tmp)

no_symlink(live);no_symlink(file);no_symlink(backups)
if not live.is_dir() or not (live/'index.php').is_file() or not file.is_file():
 fail('No se encontró CRM/archivo de disponibilidad')
if live.resolve()==backups.resolve() or live.resolve() in backups.resolve().parents:
 fail('Los respaldos deben estar fuera de public_html')
current=file.read_bytes()
if mode=='--restore':
 folder=Path(restore_path)
 no_symlink(folder)
 if folder.parent.resolve()!=backups.resolve() or not folder.is_dir():fail('Respaldo no autorizado')
 mf=folder/'manifest.json';original=folder/'original.js'
 no_symlink(mf);no_symlink(original)
 if not mf.is_file() or not original.is_file():fail('Respaldo incompleto')
 data=json.loads(mf.read_text(encoding='utf-8'))
 if data.get('file')!=str(file) or sha(original.read_bytes())!=data.get('before'):
  fail('Respaldo alterado')
 if sha(current)!=data.get('after'):fail('La versión publicada cambió; no se restaura automáticamente')
 atomic(file,original.read_bytes())
 print('OK: JS original restaurado.')
 sys.exit(0)

before=(stage/'base.js').read_bytes()
after=(stage/'target.js').read_bytes()
if gitblob(before)!=base_sha or gitblob(after)!=target_sha:
 fail('La descarga no coincide con los SHA aprobados')
if current==after:
 updated=current;label='YA ACTUALIZADO'
elif current==before:
 updated=after;label='BASE EXACTA'
else:
 a=before.splitlines(keepends=True);b=after.splitlines(keepends=True)
 groups=list(difflib.SequenceMatcher(None,a,b,autojunk=False).get_grouped_opcodes(4))
 if not groups:fail('El parche está vacío')
 patches=[]
 for group in groups:
  left=b''.join(a[group[0][1]:group[-1][2]])
  right=b''.join(b[group[0][3]:group[-1][4]])
  if not left or not right:fail('Bloque incompleto')
  patches.append((left,right))
 if all(current.count(right)==1 for _,right in patches):
  updated=current;label='YA ACTUALIZADO (PERSONALIZADO)'
 elif all(current.count(left)==1 for left,_ in patches):
  updated=current
  for left,right in patches:
   if updated.count(left)!=1:fail('Bloque duplicado o modificado')
   updated=updated.replace(left,right,1)
  label='PARCHE COMPATIBLE'
 else:
  fail('Las personalizaciones coinciden con el bloque del sonido; revisar manualmente')
decoded=updated.decode('utf-8')
if 'REMINDER_AFTER_MS=18000' not in decoded or 'status.active' not in decoded or 'request.plays>=2' not in decoded:
 fail('Código de protección sonora incompleto')
if shutil.which('node'):
 candidate=stage/'validar.js';candidate.write_bytes(updated)
 r=subprocess.run(['node','--check',str(candidate)],capture_output=True,text=True)
 if r.returncode:fail('JS inválido: '+r.stderr[:240])
print(label+': '+name)
if mode=='--check':
 print('OK: revisión compatible. '+('1 archivo preparado.' if current!=updated else 'Ya publicado.')+' SITIO INTACTO.')
 sys.exit(0)
if current==updated:
 print('OK: ya instalado. No se modificó ningún archivo.')
 sys.exit(0)
backups.mkdir(parents=True,mode=0o700,exist_ok=True)
no_symlink(backups)
folder=Path(tempfile.mkdtemp(prefix='sonido-disponibilidad-'+datetime.now().strftime('%Y%m%d-%H%M%S')+'-',dir=str(backups)))
os.chmod(folder,0o700)
original=folder/'original.js';shutil.copy2(file,original)
if sha(original.read_bytes())!=sha(current):fail('No coincidió el respaldo')
(folder/'manifest.json').write_text(json.dumps({'file':str(file),'before':sha(current),'after':sha(updated)},indent=2),encoding='utf-8')
if sha(file.read_bytes())!=sha(current):fail('El archivo cambió durante preparación')
try:
 atomic(file,updated)
 if sha(file.read_bytes())!=sha(updated):raise RuntimeError('Comprobación posterior incorrecta')
except BaseException:
 atomic(file,current)
 raise
print('OK: --apply. 1 archivo JS. Sin SQL, PHP, SDK ni transferencia.')
print('Respaldo:',folder)
print('Restaurar: bash "$HOME/publicar-sonido-disponibilidad.sh" --restore "'+str(folder)+'"')
PY
