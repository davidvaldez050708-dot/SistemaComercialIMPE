#!/usr/bin/env bash
# Ajuste VISUAL de disponibilidad: publica sólo CSS. Nunca PHP, JS, SQL ni Zadarma.
# --check (sin cambios), --apply (respaldo), --restore RUTA
set -euo pipefail
umask 077
MODE="$1"
case "$MODE" in
  --check|--apply) [ "$#" -eq 1 ] || exit 2;;
  --restore) [ "$#" -eq 2 ] || { echo "Falta respaldo" >&2; exit 2; };;
  *) echo "Uso: bash $0 --check|--apply|--restore RESPALDO" >&2; exit 2;;
esac
command -v python3 >/dev/null || { echo 'Falta python3' >&2; exit 2; }
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT
if [ "$MODE" != --restore ]; then
  if [ -n "$(printenv IMPE_UI_DISP_MIRROR 2>/dev/null || true)" ]; then
    cp "$IMPE_UI_DISP_MIRROR/base.css" "$TMP/base.css"
    cp "$IMPE_UI_DISP_MIRROR/target.css" "$TMP/target.css"
  else
    command -v curl >/dev/null || { echo 'Falta curl' >&2; exit 2; }
    URL='https://raw.githubusercontent.com/davidvaldez050708-dot/SistemaComercialIMPE'
    curl -fsSL --retry 2 "$URL/232924d000e2690fc205546d98e7b1c9cc1a0b8c/public/css/telefonia_disponibilidad.css" -o "$TMP/base.css"
    curl -fsSL --retry 2 "$URL/8c73a0fc8800b138cde354e806611768332d36a0/public/css/telefonia_disponibilidad.css" -o "$TMP/target.css"
  fi
fi
python3 - "$MODE" "$TMP" "${2:-}" <<'PY'
import hashlib,json,os,sys,tempfile,shutil
from datetime import datetime
from pathlib import Path

mode,dirpath,restore=sys.argv[1:]
stage=Path(dirpath)
live=Path(os.environ.get('IMPE_UI_DISP_LIVE') or Path.home()/'domains/fundacionrededumex.org/public_html')
backups=Path(os.environ.get('IMPE_UI_DISP_BACKUPS') or Path.home()/'respaldos-crm')
file=live/'public/css/telefonia_disponibilidad.css'
base_sha='a2d6a5ab6691b5c08c9ede784a86622efc9d45d8'
target_sha='a5b5002efa463bf646f29b0cf3ddb4503c18021f'
marker=b'/* Ajuste puntual: reloj alineado y tarjetas discretas, sin barras laterales. */'

def fail(reason):raise SystemExit('CONFLICTO: '+reason+'. No se sobrescribió el CSS.')
def no_links(path):
 if any(p.is_symlink() for p in (path,*path.parents)):fail('No se aceptan enlaces simbólicos: '+str(path))
def sha(data):return hashlib.sha256(data).hexdigest()
def blob(data):return hashlib.sha1(b'blob '+str(len(data)).encode()+b'\0'+data).hexdigest()
def atomic(path,content):
 fd,tmp=tempfile.mkstemp(prefix='.impe-ui-disponibilidad-',dir=str(path.parent))
 try:
  with os.fdopen(fd,'wb') as h:
   h.write(content);h.flush();os.fsync(h.fileno())
  os.chmod(tmp,path.stat().st_mode & 0o777)
  os.replace(tmp,path)
 finally:
  if os.path.exists(tmp):os.unlink(tmp)

no_links(live);no_links(file);no_links(backups)
if not (live/'index.php').is_file() or not file.is_file():
 fail('No se encontró la ubicación del CRM')
if live.resolve()==backups.resolve() or live.resolve() in backups.resolve().parents:
 fail('El respaldo debe estar fuera del sitio')
original=file.read_bytes()

if mode=='--restore':
 folder=Path(restore);no_links(folder)
 if not folder.is_dir() or folder.parent.resolve()!=backups.resolve():fail('Respaldo no autorizado')
 manifest=folder/'manifest.json';backup=folder/'original.css'
 no_links(manifest);no_links(backup)
 if not manifest.is_file() or not backup.is_file():fail('Respaldo incompleto')
 data=json.loads(manifest.read_text(encoding='utf-8'))
 if data.get('file')!=str(file) or sha(backup.read_bytes())!=data.get('before'):
  fail('Respaldo alterado')
 if sha(original)!=data.get('after'):fail('CSS modificado después del despliegue')
 atomic(file,backup.read_bytes())
 print('OK: CSS anterior restaurado.')
 sys.exit(0)

base=(stage/'base.css').read_bytes();target=(stage/'target.css').read_bytes()
if blob(base)!=base_sha or blob(target)!=target_sha or not target.startswith(base):
 fail('No coincide el CSS publicado en GitHub')
addition=target[len(base):]
if marker not in addition or addition.count(marker)!=1:
 fail('No se reconoció el ajuste CSS')
if addition in original:
 updated=original;status='YA ACTUALIZADO'
elif marker in original:
 fail('El ajuste existe pero está personalizado; requiere revisión')
else:
 checks=(b'/* Ajustes visuales de disponibilidad',b'.impe-availability-dialog .impe-availability-summary::before',
         b'.impe-incoming-panel .impe-availability-source',b'.impe-availability-waiting')
 if not all(x in original for x in checks):
  fail('CSS Hostinger distinto del diseño previo')
 updated=original.rstrip(b'\r\n')+b'\n'+addition
 status='PARCHE COMPATIBLE'
if updated.count(marker)!=1:fail('Marcador repetido')
if b'border-left: 4px solid #4967a2' not in original and status!='YA ACTUALIZADO':
 fail('No se reconoce el diseño previo')
updated.decode('utf-8')
print(status+': public/css/telefonia_disponibilidad.css')
if mode=='--check':
 print('OK: revisión compatible. SITIO INTACTO.')
 sys.exit(0)
if updated==original:
 print('OK: estilos ya instalados. No se modificó ningún archivo.')
 sys.exit(0)
backups.mkdir(mode=0o700,parents=True,exist_ok=True);no_links(backups)
folder=Path(tempfile.mkdtemp(prefix='disponibilidad-sobria-'+datetime.now().strftime('%Y%m%d-%H%M%S')+'-',dir=str(backups)))
os.chmod(folder,0o700)
backup=folder/'original.css'
shutil.copy2(file,backup)
if sha(backup.read_bytes())!=sha(original):fail('Respaldo incorrecto')
(folder/'manifest.json').write_text(json.dumps({'file':str(file),'before':sha(original),'after':sha(updated)},indent=2),encoding='utf-8')
if sha(file.read_bytes())!=sha(original):fail('Archivo modificado antes de instalar')
try:
 atomic(file,updated)
 if sha(file.read_bytes())!=sha(updated):raise RuntimeError('Error verificando CSS')
except BaseException:
 atomic(file,original)
 raise
print('OK: CSS de disponibilidad publicado. Ningún archivo JS/PHP/SQL modificado.')
print('Respaldo:',folder)
print('Restaurar: bash "$HOME/publicar-disponibilidad-sobria.sh" --restore "'+str(folder)+'"')
PY
