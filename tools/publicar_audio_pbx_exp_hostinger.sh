#!/usr/bin/env bash
# Cambio selectivo de un JS: --check | --apply | --restore RUTA_RESPALDO
set -euo pipefail
mode=--check
if [ "$#" -gt 0 ]; then mode="$1"; fi
extra=""
case "$mode" in
  --check|--apply) [ "$#" -le 1 ] || { echo "Argumentos adicionales" >&2; exit 2; };;
  --restore) [ "$#" -eq 2 ] || { echo "Falta ruta de respaldo" >&2; exit 2; }; extra="$2";;
  *) echo "Uso: --check|--apply|--restore RUTA" >&2; exit 2;;
esac
command -v python3 >/dev/null || { echo "Falta Python 3" >&2; exit 2; }
python3 - "$mode" "$HOME/domains/fundacionrededumex.org/public_html" "$HOME/respaldos-crm" "$extra" <<'PY'
import datetime
import hashlib
import json
import os
from pathlib import Path
import shutil
import subprocess
import sys
import tempfile

mode, public_root, backups_root, restore_arg = sys.argv[1:]
site = Path(os.environ.get('IMPE_PBX_AUDIO_LIVE') or public_root)
target = site / 'public/javascript/seguimiento_llamadas_desplegable.js'
backups = Path(os.environ.get('IMPE_PBX_AUDIO_BACKUPS') or backups_root)

def fail(message):
    raise SystemExit('ERROR: ' + message)

def safe_path(p):
    for piece in (p, *p.parents):
        if piece.is_symlink():
            fail('Enlace simbólico no permitido: ' + str(piece))

def sha(data):
    return hashlib.sha256(data).hexdigest()

def store(data, path):
    fd, name = tempfile.mkstemp(prefix='.impe-pbx-', suffix='.tmp', dir=str(path.parent))
    try:
        with os.fdopen(fd, 'wb') as out:
            out.write(data)
            out.flush()
            os.fsync(out.fileno())
        os.chmod(name, path.stat().st_mode & 0o777)
        os.replace(name, path)
    finally:
        if os.path.exists(name):
            os.unlink(name)

def verify_syntax(data):
    if not shutil.which('node'):
        return
    with tempfile.TemporaryDirectory(prefix='impe-pbx-check-') as folder:
        path = Path(folder) / 'player.js'
        path.write_bytes(data)
        outcome = subprocess.run(['node', '--check', str(path)], capture_output=True, text=True)
        if outcome.returncode:
            fail('JavaScript inválido: ' + outcome.stderr.strip())

safe_path(site)
safe_path(target)
safe_path(backups)
if not site.is_dir() or not (site / 'index.php').is_file() or not target.is_file():
    fail('No se encontró el sitio o el JavaScript del expediente.')
if site.resolve() == backups.resolve() or site.resolve() in backups.resolve().parents:
    fail('El respaldo debe estar fuera de public_html.')

before = target.read_bytes()
original_sha = sha(before)
if mode == '--restore':
    backup = Path(restore_arg)
    safe_path(backup)
    if backup.parent.resolve() != backups.resolve():
        fail('Respaldo fuera de la carpeta autorizada.')
    manifest_file = backup / 'manifest.json'
    original_file = backup / 'original.js'
    safe_path(manifest_file)
    safe_path(original_file)
    if not manifest_file.is_file() or not original_file.is_file():
        fail('Respaldo incompleto.')
    manifest = json.loads(manifest_file.read_text(encoding='utf-8'))
    source = original_file.read_bytes()
    if manifest.get('path') != str(target) or sha(source) != manifest.get('before'):
        fail('El respaldo no coincide con su manifiesto.')
    if original_sha != manifest.get('after'):
        fail('El archivo publicado cambió; no se sobrescribe.')
    store(source, target)
    print('OK: restaurado', target)
    sys.exit(0)

eol = b'\r\n' if b'\r\n' in before else b'\n'
head = b"        const convertirBoton = function (tarjeta, llamada) {" + eol
next_line = b"            if (tarjeta.querySelector('[data-call-recording-toggle]')) return;"
guard = b"            if (tarjeta.querySelector('.linkage-incoming-audio-panel')) return;"
comment1 = b'            // Respetar el reproductor PBX de llamadas transferidas.'
comment2 = b'            // No reemplazar su boton ni abrir un panel de audio vacio.'
replacement = head + comment1 + eol + comment2 + eol + guard + eol + next_line
anchor = head + next_line
if replacement in before:
    print('OK: protección PBX ya aplicada. No se modificó ningún archivo.')
    sys.exit(0)
if before.count(anchor) != 1 or b"const crearReproductor = function (llamada, tarjeta, boton)" not in before:
    fail('Variante Hostinger no reconocida; sitio intacto.')
if guard in before:
    fail('Ya existe otra protección; se requiere revisión.')
after = before.replace(anchor, replacement, 1)
verify_syntax(after)
print('PREPARADO: public/javascript/seguimiento_llamadas_desplegable.js')
print('SHA-256 original:', original_sha)
print('SHA-256 nuevo:   ', sha(after))
if mode == '--check':
    print('OK: revisión compatible. 1 archivo preparado. SITIO INTACTO.')
    sys.exit(0)

backups.mkdir(mode=0o700, parents=True, exist_ok=True)
safe_path(backups)
stamp = datetime.datetime.now().strftime('%Y%m%d-%H%M%S')
backup = Path(tempfile.mkdtemp(prefix='audio-pbx-expediente-' + stamp + '-', dir=str(backups)))
os.chmod(backup, 0o700)
original_file = backup / 'original.js'
shutil.copy2(target, original_file)
if sha(original_file.read_bytes()) != original_sha:
    fail('El respaldo no coincide con el original; no se publica.')
manifest = {'path':str(target), 'before':original_sha, 'after':sha(after)}
(backup / 'manifest.json').write_text(json.dumps(manifest, indent=2), encoding='utf-8')
if sha(target.read_bytes()) != original_sha:
    fail('El archivo cambió durante la preparación; no se publica.')
try:
    store(after, target)
    if sha(target.read_bytes()) != sha(after):
        fail('La instalación no coincide con lo preparado.')
except BaseException:
    store(original_file.read_bytes(), target)
    raise
print('OK: --apply terminado. 1 archivo. Sin SQL ni cambios telefónicos.')
print('Respaldo:', backup)
print('Rollback: bash "$HOME/publicar-audio-pbx-expediente.sh" --restore "' + str(backup) + '"')
PY
