#!/usr/bin/env bash
# Transferencia responsive: únicamente CSS y JS visuales del shell.
# Uso: --check | --apply | --restore RUTA_RESPALDO
set -euo pipefail
umask 077
MODE=--check
if [ "$#" -gt 0 ]; then MODE="$1"; fi
RESTORE=""
case "$MODE" in
  --check|--apply) [ "$#" -le 1 ] || exit 2;;
  --restore) [ "$#" -eq 2 ] || { echo "Falta ruta de respaldo" >&2; exit 2; }; RESTORE="$2";;
  *) echo "Uso: bash $0 --check|--apply|--restore RUTA_RESPALDO" >&2; exit 2;;
esac
command -v python3 >/dev/null || { echo "ERROR: Falta Python3" >&2; exit 2; }
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

if [ "$MODE" != --restore ]; then
  if [ -n "$(printenv IMPE_ROUTING_MIRROR_DIR 2>/dev/null || true)" ]; then
    for label in base-js base-css target-js target-css; do
      cp "$IMPE_ROUTING_MIRROR_DIR/$label" "$TMP/$label"
    done
  else
    command -v curl >/dev/null || { echo "ERROR: Falta curl" >&2; exit 2; }
    URL=https://raw.githubusercontent.com/davidvaldez050708-dot/SistemaComercialIMPE
    BASE=b8b96e51e46d56a705c2900379b83ccb75355450
    TARGET=94d24dca8b47e2383938b531e139711a8a8b7f96
    curl -fsSL --retry 2 "$URL/$BASE/public/javascript/telefonia_enrutamiento.js" -o "$TMP/base-js"
    curl -fsSL --retry 2 "$URL/$BASE/public/css/telefonia_enrutamiento.css" -o "$TMP/base-css"
    curl -fsSL --retry 2 "$URL/$TARGET/public/javascript/telefonia_enrutamiento.js" -o "$TMP/target-js"
    curl -fsSL --retry 2 "$URL/$TARGET/public/css/telefonia_enrutamiento.css" -o "$TMP/target-css"
  fi
fi

python3 - "$MODE" "$TMP" "$RESTORE" <<'PY'
import difflib
import hashlib
import json
import os
from pathlib import Path
import shutil
import subprocess
import sys
import tempfile
from datetime import datetime

mode, stage_name, restore_name = sys.argv[1:]
stage = Path(stage_name)
live = Path(os.environ.get('IMPE_ROUTING_LIVE') or Path.home() / 'domains/fundacionrededumex.org/public_html')
backups = Path(os.environ.get('IMPE_ROUTING_BACKUPS') or Path.home() / 'respaldos-crm')

files = [
    ('public/javascript/telefonia_enrutamiento.js', 'js',
     'bb95ab5dcfa00651e80f18129ac40759c3e18ade',
     'a5ed6de6f67cee97a22650b063e8a051d2d4fad1'),
    ('public/css/telefonia_enrutamiento.css', 'css',
     '82c2d9f4e56ed8dd582da26347390467f0a0063b',
     '7a3a6a26b61aac5faa88a991c017cc81658645cd'),
]

def fail(reason):
    raise SystemExit('CONFLICTO: ' + reason + '. SITIO INTACTO.')

def hash256(data):
    return hashlib.sha256(data).hexdigest()

def git_blob(data):
    return hashlib.sha1(b'blob ' + str(len(data)).encode() + b'\0' + data).hexdigest()

def avoid_symlink(path):
    if any(part.is_symlink() for part in (path, *path.parents)):
        fail('No se aceptan enlaces simbólicos: ' + str(path))

def put_atomic(path, content):
    fd, tempname = tempfile.mkstemp(prefix='.impe-sidecar-', dir=str(path.parent))
    try:
        with os.fdopen(fd, 'wb') as dest:
            dest.write(content)
            dest.flush()
            os.fsync(dest.fileno())
        os.chmod(tempname, path.stat().st_mode & 0o777)
        os.replace(tempname, path)
    finally:
        if os.path.exists(tempname):
            os.unlink(tempname)

avoid_symlink(live)
avoid_symlink(backups)
if not live.is_dir() or not (live / 'index.php').is_file():
    fail('No existe la raíz del CRM')
if live.resolve() == backups.resolve() or live.resolve() in backups.resolve().parents:
    fail('El respaldo debe estar fuera de public_html')

paths = {rel: live / rel for rel, _, _, _ in files}
for rel, path in paths.items():
    avoid_symlink(path)
    if not path.is_file():
        fail('No existe ' + rel)

if mode == '--restore':
    backup = Path(restore_name)
    avoid_symlink(backup)
    if backup.parent.resolve() != backups.resolve() or not backup.is_dir():
        fail('Respaldo no autorizado')
    manifest_path = backup / 'manifest.json'
    avoid_symlink(manifest_path)
    if not manifest_path.is_file():
        fail('Manifiesto de respaldo ausente')
    manifest = json.loads(manifest_path.read_text(encoding='utf-8'))
    pending = []
    for rel, _, _, _ in files:
        entry = manifest.get('files', {}).get(rel)
        if not isinstance(entry, dict):
            fail('Respaldo incompleto para ' + rel)
        source = backup / rel
        avoid_symlink(source)
        if not source.is_file():
            fail('Copia original ausente ' + rel)
        original = source.read_bytes()
        if hash256(original) != entry.get('before'):
            fail('Respaldo alterado ' + rel)
        current = paths[rel].read_bytes()
        if hash256(current) != entry.get('after'):
            fail('El archivo cambió después de publicar: ' + rel)
        pending.append((paths[rel], original))
    for path, content in pending:
        put_atomic(path, content)
    print('OK: respaldo restaurado para 2 archivos.')
    sys.exit(0)

def prepare(current, old, new, label):
    if current == new:
        return current, 'YA ACTUALIZADO'
    if current == old:
        return new, 'BASE EXACTA'
    # Bloques con cinco líneas de contexto: preservar personalizaciones ajenas
    # al cambio. Ante ambigüedad o variación de los bloques, detener la operación.
    original_lines = old.splitlines(keepends=True)
    target_lines = new.splitlines(keepends=True)
    blocks = difflib.SequenceMatcher(None, original_lines, target_lines, autojunk=False)
    output = current
    groups = list(blocks.get_grouped_opcodes(5))
    if not groups:
        fail('No hay cambios esperados en ' + label)
    patches = []
    for group in groups:
        start = group[0][1]
        end = group[-1][2]
        start_new = group[0][3]
        end_new = group[-1][4]
        before = b''.join(original_lines[start:end])
        after = b''.join(target_lines[start_new:end_new])
        if not before or not after:
            fail('Bloque incompleto de ' + label)
        patches.append((before, after))
    # Si ya están presentes TODOS los bloques nuevos, no volver a parchear
    # una variante con personalizaciones ajenas al cambio.
    if all(current.count(after) == 1 for _, after in patches):
        return current, 'YA ACTUALIZADO (PERSONALIZADO)'
    if not all(current.count(before) == 1 for before, _ in patches):
        fail('Versión personalizada incompatible de ' + label)
    for before, after in patches:
        output = output.replace(before, after, 1)
    return output, 'PARCHE COMPATIBLE'

prepared = {}
for rel, tag, base_sha, target_sha in files:
    before_src = (stage / ('base-' + tag)).read_bytes()
    after_src = (stage / ('target-' + tag)).read_bytes()
    if git_blob(before_src) != base_sha or git_blob(after_src) != target_sha:
        fail('Los archivos descargados no corresponden a la revisión aprobada')
    current = paths[rel].read_bytes()
    updated, label = prepare(current, before_src, after_src, rel)
    if rel.endswith('.js'):
        text = updated.decode('utf-8')
        if 'window.visualViewport?.addEventListener' not in text or 'positionSidecar' not in text:
            fail('El posicionador JS no tiene los controles esperados')
        if shutil.which('node'):
            scratch = stage / 'sidecar-check.js'
            scratch.write_bytes(updated)
            result = subprocess.run(['node', '--check', str(scratch)], capture_output=True, text=True)
            if result.returncode:
                fail('JS con errores de sintaxis: ' + result.stderr[:250])
    if rel.endswith('.css'):
        text = updated.decode('utf-8')
        if 'overflow-x: hidden' not in text or 'height: 40px' not in text:
            fail('Los estilos del cierre no están presentes')
    print(label + ': ' + rel)
    prepared[rel] = {'before': current, 'after': updated}

if mode == '--check':
    changed = sum(p['before'] != p['after'] for p in prepared.values())
    print('OK: revisión compatible. ' + str(changed) + ' archivo(s) por publicar. SITIO INTACTO.')
    sys.exit(0)

pending = [(rel, item) for rel, item in prepared.items() if item['before'] != item['after']]
if not pending:
    print('OK: diseño responsive ya instalado. Ningún archivo modificado.')
    sys.exit(0)

backups.mkdir(mode=0o700, parents=True, exist_ok=True)
avoid_symlink(backups)
backup = Path(tempfile.mkdtemp(prefix='transferencia-responsive-' + datetime.now().strftime('%Y%m%d-%H%M%S') + '-', dir=str(backups)))
os.chmod(backup, 0o700)
manifest = {'files': {}}
for rel, item in prepared.items():
    source = backup / rel
    source.parent.mkdir(parents=True, exist_ok=True)
    shutil.copy2(paths[rel], source)
    if hash256(source.read_bytes()) != hash256(item['before']):
        fail('No coincide el respaldo de ' + rel)
    manifest['files'][rel] = {'before': hash256(item['before']), 'after': hash256(item['after'])}
(backup / 'manifest.json').write_text(json.dumps(manifest, indent=2), encoding='utf-8')
for rel, item in prepared.items():
    if hash256(paths[rel].read_bytes()) != manifest['files'][rel]['before']:
        fail('Archivo modificado durante la comprobación: ' + rel)
done = []
try:
    for rel, item in pending:
        put_atomic(paths[rel], item['after'])
        done.append(rel)
        if hash256(paths[rel].read_bytes()) != hash256(item['after']):
            raise RuntimeError('Error de comprobación posterior: ' + rel)
except BaseException:
    for rel in reversed(done):
        put_atomic(paths[rel], prepared[rel]['before'])
    raise
print('OK: --apply terminado. ' + str(len(pending)) + ' archivo(s). Sin PHP, SQL ni cambios en el SDK.')
print('Respaldo:', backup)
print('Rollback: bash "$HOME/publicar-transferencia-responsive.sh" --restore "' + str(backup) + '"')
PY
