#!/usr/bin/env python3
"""Parche visual selectivo de Prospectos. No ejecuta SQL ni toca formularios/telefonía."""
import argparse
import datetime
import hashlib
import json
import os
from pathlib import Path
import subprocess
import sys
import tempfile
from urllib.request import urlopen

REPO = 'davidvaldez050708-dot/SistemaComercialIMPE'
SOURCE_COMMIT = '34e65eb3ab172a3e79ef08f5022c2c977eac0f06'
FILES = {
    'app/controllers/ProspectoController.php': (
        'd02d51f74f964d758d03c03c5bd4cb088b4cdff585fc6900afe5f5d330848b02',
        '88ffd3ddc008f176958296ef64d776090eaaf1738921de8a86c67691fa4d7df2'),
    'app/views/prospectos/central.php': (
        '150b319bc91092ccb49fba735fc45578aba3458e16c597a0ffa8a4a596c841ed',
        '01d413734685df07bf25b01c5068b55b3e143986d110913131c860ab72f49a84'),
    'public/css/prospectos_central.css': (
        'ffa03ffa06390041a3404abdffa9b51a223f51771a791ce78244b717683ca3b8',
        '49331e13e81b94876767050b296a1e1ce5918c36362fc9aba0edd32333bba473'),
}


def digest(data):
    return hashlib.sha256(data).hexdigest()


def safe_path(site, relative):
    if os.path.isabs(relative) or '..' in Path(relative).parts or '\\' in relative:
        raise RuntimeError('Ruta del paquete inválida.')
    result = site / relative
    if result.is_symlink() or site not in result.resolve().parents:
        raise RuntimeError('Ruta fuera de la instalación: ' + relative)
    return result


def put_atomic(target, data, mode):
    target.parent.mkdir(parents=True, exist_ok=True)
    fd, temp = tempfile.mkstemp(prefix='.prospectos-compacto-', dir=str(target.parent))
    try:
        with os.fdopen(fd, 'wb') as output:
            output.write(data)
            output.flush()
            os.fsync(output.fileno())
        os.chmod(temp, mode)
        os.replace(temp, str(target))
    finally:
        if os.path.exists(temp):
            os.unlink(temp)


def download(relative, expected):
    url = 'https://raw.githubusercontent.com/{}/{}/{}'.format(REPO, SOURCE_COMMIT, relative)
    with urlopen(url, timeout=30) as response:
        content = response.read(1024 * 1024 + 1)
    if len(content) > 1024 * 1024 or digest(content) != expected:
        raise RuntimeError('Origen remoto inválido: ' + relative)
    return content


def lint_php(content):
    fd, temp = tempfile.mkstemp(prefix='prospectos-compacto-', suffix='.php')
    try:
        with os.fdopen(fd, 'wb') as output:
            output.write(content)
        check = subprocess.run(['php', '-l', temp], stdout=subprocess.PIPE, stderr=subprocess.PIPE)
        if check.returncode:
            raise RuntimeError('Sintaxis PHP inválida en el paquete.')
    finally:
        if os.path.exists(temp):
            os.unlink(temp)


def restore(site, backup):
    manifest = json.loads((backup / 'respaldo.json').read_text(encoding='utf-8'))
    if manifest.get('site') != str(site) or set(x['path'] for x in manifest['files']) != set(FILES):
        raise RuntimeError('Respaldo no corresponde a este sitio o a esta entrega.')
    changes = []
    for entry in manifest['files']:
        relative = entry['path']
        original = safe_path(backup / 'original', relative).read_bytes()
        current = safe_path(site, relative).read_bytes()
        if digest(current) != FILES[relative][1] or digest(original) != FILES[relative][0]:
            raise RuntimeError('No se restaura: archivo cambiado o respaldo incorrecto: ' + relative)
        changes.append((safe_path(site, relative), original, entry['mode']))
    for target, original, mode in reversed(changes):
        put_atomic(target, original, mode)
    print('RESTAURADO: versión visual anterior. Sin SQL ni cambios en prospectos.')


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--site', required=True)
    parser.add_argument('--backup-root', default=str(Path.home() / 'respaldos-impe-prospectos-compacto'))
    mode = parser.add_mutually_exclusive_group(required=True)
    mode.add_argument('--check', action='store_true')
    mode.add_argument('--apply', action='store_true')
    mode.add_argument('--restore')
    args = parser.parse_args()
    site = Path(args.site).resolve()
    backup_root = Path(args.backup_root).resolve()
    if not (site / 'index.php').is_file() or backup_root == site or site in backup_root.parents:
        raise RuntimeError('Sitio o carpeta de respaldo inválida.')
    if args.restore:
        restore(site, Path(args.restore).resolve())
        return
    if not shutil_which('php'):
        raise RuntimeError('Se requiere PHP CLI para comprobar el paquete.')

    pending = []
    for relative, (before_hash, after_hash) in FILES.items():
        target = safe_path(site, relative)
        if not target.is_file():
            raise RuntimeError('Archivo requerido ausente: ' + relative)
        original = target.read_bytes()
        actual = digest(original)
        if actual == after_hash:
            print('YA INSTALADO: ' + relative)
        elif actual == before_hash:
            pending.append((relative, target, original, target.stat().st_mode & 0o777))
            print('COMPATIBLE: ' + relative)
        else:
            raise RuntimeError('VERSIÓN DIFERENTE: ' + relative + '. Sitio intacto.')

    prepared = []
    for relative, target, original, permission in pending:
        desired = download(relative, FILES[relative][1])
        if relative.endswith('.php'):
            lint_php(desired)
        prepared.append((relative, target, original, desired, permission))
    if args.check:
        print('CHECK OK: parche visual compatible, sin escrituras ni SQL.')
        return
    if not prepared:
        print('SIN CAMBIOS: el ajuste visual ya está instalado.')
        return

    # Verificación de todas las versiones justo antes de crear el respaldo.
    for relative, target, original, desired, permission in prepared:
        if target.read_bytes() != original:
            raise RuntimeError('Archivo modificado durante la revisión: ' + relative)
    backup_root.mkdir(parents=True, exist_ok=True)
    os.chmod(str(backup_root), 0o700)
    backup = Path(tempfile.mkdtemp(prefix=datetime.datetime.utcnow().strftime('%Y%m%d-%H%M%S-'), dir=str(backup_root)))
    records = []
    for relative, target, original, desired, permission in prepared:
        put_atomic(safe_path(backup / 'original', relative), original, 0o600)
        records.append({'path': relative, 'mode': permission})
    put_atomic(backup / 'respaldo.json', json.dumps({'site': str(site), 'files': records}, indent=2).encode('utf-8'), 0o600)
    changed = []
    try:
        for relative, target, original, desired, permission in prepared:
            if target.read_bytes() != original:
                raise RuntimeError('Cambio concurrente: ' + relative)
            put_atomic(target, desired, permission)
            changed.append((target, original, permission))
            if digest(target.read_bytes()) != FILES[relative][1]:
                raise RuntimeError('Verificación posterior fallida: ' + relative)
    except Exception:
        for target, original, permission in reversed(changed):
            put_atomic(target, original, permission)
        raise
    print('PUBLICADO: ajuste visual compacto en tres archivos. Sin SQL ni conciliación.')
    print('RESPALDO: ' + str(backup))


def shutil_which(name):
    for folder in os.environ.get('PATH', '').split(os.pathsep):
        candidate = os.path.join(folder, name)
        if os.path.isfile(candidate) and os.access(candidate, os.X_OK):
            return candidate
    return None


if __name__ == '__main__':
    try:
        main()
    except Exception as error:
        print('ERROR: ' + str(error), file=sys.stderr)
        sys.exit(1)
