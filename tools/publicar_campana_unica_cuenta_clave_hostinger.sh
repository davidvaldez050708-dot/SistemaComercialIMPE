#!/usr/bin/env bash
# Publicacion selectiva de una sola campana por rol; sin SQL ni cambios a otros modulos.
set -euo pipefail
python3 - "$@" <<'PY'
import hashlib
import json
import os
import pathlib
import shutil
import stat
import subprocess
import sys
import tempfile

def fail(message):
    sys.stderr.write('CONFLICTO: ' + message + '\n')
    sys.exit(2)

def digest(data):
    return hashlib.sha256(data).hexdigest()

def atomic_write(path, data, permissions):
    fd, temporary = tempfile.mkstemp(prefix='.impe-campana-', dir=str(path.parent))
    try:
        os.fchmod(fd, permissions)
        with os.fdopen(fd, 'wb') as output:
            output.write(data)
            output.flush()
            os.fsync(output.fileno())
        os.replace(temporary, str(path))
    finally:
        if os.path.exists(temporary):
            os.unlink(temporary)

if len(sys.argv) < 2 or sys.argv[1] not in ('--check', '--apply', '--restore'):
    fail('Uso: IMPE_CRM_PUBLIC=... bash publicador --check|--apply|--restore [ruta_respaldo]')

mode = sys.argv[1]
if (mode == '--restore' and len(sys.argv) != 3) or (mode != '--restore' and len(sys.argv) != 2):
    fail('Argumentos incorrectos')

site_env = os.environ.get('IMPE_CRM_PUBLIC', '')
if not site_env:
    fail('Falta IMPE_CRM_PUBLIC')

site = pathlib.Path(site_env).resolve()
if not (site / 'index.php').is_file():
    fail('No se reconoce la raiz del CRM')

backups = pathlib.Path(os.environ.get('IMPE_CRM_BACKUPS', str(pathlib.Path.home() / 'impe-campana-unica-backups'))).resolve()
if backups == site or backups in site.parents or site in backups.parents:
    fail('El respaldo debe estar fuera del sitio publico')

relative = 'app/views/layout/topbar.php'
path = site / relative
if path.is_symlink() or site not in path.resolve().parents or not path.is_file():
    fail('Archivo topbar inexistente o ruta insegura')

old_role = """$esMarketingTopbar =
    !$esAdministradorTopbar &&
    tienePermiso('convocatorias.gestionar');"""
new_role = """// Un permiso compartido no convierte a Cuenta Clave en Marketing.
$esMarketingTopbar =
    !$esAdministradorTopbar &&
    strcasecmp(trim((string)($_SESSION['rol'] ?? '')), 'Marketing') === 0 &&
    tienePermiso('convocatorias.gestionar');"""

old_general = """    tienePermiso('aliados.seguimiento_convocatorias');"""
new_general = """    tienePermiso('aliados.seguimiento_convocatorias');
// Solo una campana: Marketing utiliza su centro de Convocatorias.
if ($esMarketingTopbar) {
    $mostrarCentroAvisos = false;
}"""

patches = [(old_role, new_role), (old_general, new_general)]

if mode == '--restore':
    requested = pathlib.Path(sys.argv[2])
    if requested.is_symlink() or not requested.is_dir():
        fail('Respaldo inexistente o inseguro')
    folder = requested.resolve()
    if backups not in folder.parents:
        fail('Respaldo fuera del directorio autorizado')
    manifest_path = folder / 'manifest.json'
    backup_path = folder / 'topbar.bak'
    if manifest_path.is_symlink() or backup_path.is_symlink() or not manifest_path.is_file() or not backup_path.is_file():
        fail('Respaldo incompleto')
    manifest = json.loads(manifest_path.read_text(encoding='utf8'))
    before = backup_path.read_bytes()
    if manifest.get('file') != relative or digest(before) != manifest.get('before'):
        fail('Respaldo incorrecto o alterado')
    current = path.read_bytes()
    if digest(current) != manifest.get('after'):
        fail('El topbar fue modificado despues de publicar; no restaurar a ciegas')
    atomic_write(path, before, int(manifest['mode']))
    print('OK: restaurado topbar original; archivos adicionales, BD y llamadas intactos.')
    sys.exit(0)

raw = path.read_bytes()
try:
    source = raw.decode('utf8')
except UnicodeDecodeError:
    fail('topbar no es UTF-8')

if b'\r\n' in raw and raw.count(b'\r\n') != raw.count(b'\n'):
    fail('Saltos de linea mixtos en topbar')

crlf = b'\r\n' in raw
source = source.replace('\r\n', '\n')
updated = source
pending = 0
already = 0
for previous, replacement in patches:
    if updated.count(replacement) == 1:
        already += 1
    elif updated.count(previous) == 1:
        updated = updated.replace(previous, replacement, 1)
        pending += 1
    else:
        fail('Personalizacion desconocida o duplicada: ' + relative)

if pending and already:
    fail('topbar parcialmente modificado; no se publica')
if updated.count('data-convocatoria-notification-root') != 1 or updated.count('data-reminder-root') != 1:
    fail('Estructura de campanas distinta de la esperada')

target = updated.replace('\n', '\r\n').encode('utf8') if crlf else updated.encode('utf8')
permissions = stat.S_IMODE(path.stat().st_mode)

if pending:
    if not shutil.which('php'):
        fail('Se requiere PHP para validar sintaxis')
    fd, temporary = tempfile.mkstemp(suffix='.php')
    try:
        with os.fdopen(fd, 'wb') as output:
            output.write(target)
        check = subprocess.run(['php', '-l', temporary], stdout=subprocess.PIPE, stderr=subprocess.PIPE)
        if check.returncode:
            fail('Error de sintaxis PHP: ' + (check.stdout + check.stderr).decode('utf8', 'replace')[-500:])
    finally:
        if os.path.exists(temporary):
            os.unlink(temporary)

print('Topbar: ' + ('COMPATIBLE, pendiente' if pending else 'YA CORREGIDO'))
print('Archivos a modificar: ' + ('1' if pending else '0'))

if mode == '--check':
    print('OK: comprobacion de solo lectura; sitio intacto.')
    sys.exit(0)
if not pending:
    print('OK: correccion ya aplicada, sin cambios.')
    sys.exit(0)
if path.read_bytes() != raw:
    fail('topbar modificado simultaneamente')

backups.mkdir(parents=True, exist_ok=True)
os.chmod(str(backups), 0o700)
folder = pathlib.Path(tempfile.mkdtemp(prefix='campana-unica-', dir=str(backups)))
os.chmod(str(folder), 0o700)
backup_path = folder / 'topbar.bak'
backup_path.write_bytes(raw)
os.chmod(str(backup_path), 0o600)
manifest = {
    'file': relative, 'before': digest(raw), 'after': digest(target),
    'mode': permissions,
}
manifest_path = folder / 'manifest.json'
manifest_path.write_text(json.dumps(manifest, indent=2), encoding='utf8')
os.chmod(str(manifest_path), 0o600)
try:
    if path.read_bytes() != raw:
        fail('topbar modificado antes de publicar')
    atomic_write(path, target, permissions)
except BaseException:
    if path.is_file() and path.read_bytes() == target:
        atomic_write(path, raw, permissions)
    raise
print('OK: campana unica publicada. Respaldo: ' + str(folder))
print('Sin modificar permisos, JavaScript, estilos, llamadas ni base de datos.')
PY
