#!/usr/bin/env python3
"""Sidebar plegable: publicador selectivo offline, Python 3.6+. Sin SQL."""
import argparse
import base64
import datetime
import gzip
import hashlib
import json
import os
from pathlib import Path
import shutil
import subprocess
import sys
import tempfile

FILES = (
    'public/css/sidebar_plegable.css',
    'public/javascript/sidebar_plegable.js',
    'app/views/layout/topbar.php',
)
DEPENDENCIES = (
    'app/views/layout/sidebar.php',
    'public/css/global.css',
    'public/css/responsive_sistema.css',
)


def sha(data):
    return hashlib.sha256(data).hexdigest()


def lf(data):
    return data.replace(b'\r\n', b'\n')


def inside(path, root):
    return path == root or root in path.parents


def target(site, name):
    if name not in FILES + DEPENDENCIES:
        raise RuntimeError('Archivo ajeno a esta entrega: ' + name)
    rel = Path(name)
    if rel.is_absolute() or '..' in rel.parts:
        raise RuntimeError('Ruta insegura: ' + name)
    p = site / rel
    if not inside(p.resolve(), site) or p.is_symlink():
        raise RuntimeError('Enlace/ruta fuera del sitio: ' + name)
    return p


def unpack(value):
    return None if value is None else gzip.decompress(base64.b64decode(value))


def prepare_candidate(row, current, stage):
    desired = unpack(row['target'])
    if sha(desired) != row['sha256']:
        raise RuntimeError('Entrega corrupta: ' + row['path'])
    if current is not None and lf(current) == desired:
        return current
    actual = sha(lf(current)) if current is not None else None
    if actual != row['base_sha256']:
        raise RuntimeError('VERSIÓN DIFERENTE. Sitio intacto; comparar antes de publicar.')
    return desired.replace(b'\n', b'\r\n') if current is not None and b'\r\n' in current else desired


def atomic(path, data, mode=0o644):
    path.parent.mkdir(parents=True, exist_ok=True)
    fd, name = tempfile.mkstemp(prefix='.sidebar-plegable-', dir=str(path.parent))
    try:
        with os.fdopen(fd, 'wb') as f:
            f.write(data)
            f.flush()
            os.fsync(f.fileno())
        os.chmod(name, mode)
        os.replace(name, str(path))
    finally:
        if os.path.exists(name):
            os.unlink(name)


def restore(site, backup):
    info = json.loads((backup / 'respaldo.json').read_text(encoding='utf-8'))
    if ([row['path'] for row in info['files']] != [name for name in FILES if any(row['path'] == name for row in info['files'])] or
            info['site'] != str(site)):
        raise RuntimeError('El respaldo pertenece a otro sitio')
    for row in info['files']:
        p = target(site, row['path'])
        if not p.is_file() or sha(p.read_bytes()) != row['installed']:
            raise RuntimeError('Restauración detenida: archivo modificado desde la publicación: ' + row['path'])
        original = backup / 'original' / row['path']
        if row['original'] is not None and (not original.is_file() or sha(original.read_bytes()) != row['original']):
            raise RuntimeError('Respaldo original incompleto')
    for row in reversed(info['files']):
        p = target(site, row['path'])
        if row['original'] is None:
            p.unlink()
        else:
            atomic(p, (backup / 'original' / row['path']).read_bytes(), row['mode'])
    print('RESTAURADO: archivos originales del sidebar. Sin SQL.')


def main():
    os.umask(0o077)
    parser = argparse.ArgumentParser(description=__doc__)
    mode = parser.add_mutually_exclusive_group(required=True)
    mode.add_argument('--check', action='store_true')
    mode.add_argument('--apply', action='store_true')
    mode.add_argument('--restore', metavar='RESPALDO')
    parser.add_argument('--site', required=True)
    parser.add_argument('--backup-root', default=str(Path.home() / 'respaldos-impe-sidebar-plegable'))
    parser.add_argument('--manifest', default=str(Path(__file__).with_name('sidebar_plegable_release.json')))
    args = parser.parse_args()
    site = Path(args.site).resolve()
    if not (site / 'index.php').is_file():
        raise RuntimeError('Sitio inválido: falta index.php')
    private = Path(args.backup_root).resolve()
    if inside(private, site):
        raise RuntimeError('Los respaldos deben estar fuera de public_html')
    if args.restore:
        backup = Path(args.restore).resolve()
        if inside(backup, site):
            raise RuntimeError('Respaldo dentro del sitio')
        restore(site, backup)
        return
    manifest = json.loads(Path(args.manifest).read_text(encoding='utf-8'))
    if manifest.get('schema') != 1 or [row['path'] for row in manifest['files']] != list(FILES):
        raise RuntimeError('Manifiesto incompatible')
    if [row['path'] for row in manifest.get('dependencies', [])] != list(DEPENDENCIES):
        raise RuntimeError('Dependencias incompatibles')
    dependency_bytes = {}
    for row in manifest['dependencies']:
        p = target(site, row['path'])
        data = p.read_bytes() if p.is_file() else None
        if data is None or sha(lf(data)) != row['sha256']:
            raise RuntimeError('DEPENDENCIA DIFERENTE: ' + row['path'] + '. Sitio intacto; comparar antes de publicar.')
        dependency_bytes[row['path']] = data
    php = os.environ.get('PHP_BINARY') or shutil.which('php')
    if not php:
        raise RuntimeError('Se requiere PHP para revisar sintaxis')
    private.mkdir(parents=True, exist_ok=True)
    os.chmod(str(private), 0o700)
    with tempfile.TemporaryDirectory(prefix='revision-', dir=str(private)) as temp:
        stage = Path(temp)
        plan = []
        for row in manifest['files']:
            name = row['path']
            p = target(site, name)
            if p.exists() and not p.is_file():
                raise RuntimeError('Se esperaba un archivo: ' + name)
            original = p.read_bytes() if p.is_file() else None
            try:
                reviewed = prepare_candidate(row, original, stage)
            except RuntimeError as e:
                raise RuntimeError(name + ': ' + str(e))
            if reviewed == original:
                print('SIN CAMBIOS: ' + name)
                continue
            file = stage / 'candidatos' / name
            file.parent.mkdir(parents=True, exist_ok=True)
            file.write_bytes(reviewed)
            if name.endswith('.php'):
                run = subprocess.run([php, '-l', str(file)], stdout=subprocess.PIPE, stderr=subprocess.PIPE)
                if run.returncode:
                    raise RuntimeError('Sintaxis PHP inválida: ' + name)
            if name.endswith('.js') and shutil.which('node'):
                run = subprocess.run(['node', '--check', str(file)], stdout=subprocess.PIPE, stderr=subprocess.PIPE)
                if run.returncode:
                    raise RuntimeError('Sintaxis JavaScript inválida: ' + name)
            plan.append((name, p, original, reviewed, p.stat().st_mode & 0o777 if p.exists() else 0o644))
            print('PREPARADO: ' + name)
        print('REVISIÓN COMPATIBLE: {} archivos. No se ejecutó SQL.'.format(len(plan)))
        if args.check or not plan:
            print('SITIO INTACTO' if args.check else 'Entrega ya publicada')
            return
        # Todos los archivos se revisan antes de la primera escritura al sitio.
        for name, p, original, candidate, mode in plan:
            now = p.read_bytes() if p.is_file() else None
            if now != original:
                raise RuntimeError('Cambio concurrente: ' + name)
        for name, original in dependency_bytes.items():
            if target(site, name).read_bytes() != original:
                raise RuntimeError('Cambio concurrente de dependencia: ' + name)
        backup = private / ('sidebar-' + datetime.datetime.utcnow().strftime('%Y%m%d-%H%M%S-%f'))
        backup.mkdir(mode=0o700)
        info = {'site': str(site), 'release': manifest['source'], 'files': []}
        for name, p, original, candidate, mode in plan:
            if original is not None:
                saved = backup / 'original' / name
                saved.parent.mkdir(parents=True, exist_ok=True)
                saved.write_bytes(original)
            info['files'].append({'path': name, 'original': sha(original) if original is not None else None, 'installed': sha(candidate), 'mode': mode})
        (backup / 'respaldo.json').write_text(json.dumps(info, indent=2), encoding='utf-8')
        completed = []
        try:
            for name, p, original, candidate, mode in plan:
                now = p.read_bytes() if p.is_file() else None
                if now != original:
                    raise RuntimeError('Cambio concurrente: ' + name)
                atomic(p, candidate, mode)
                completed.append((p, original, mode))
                if sha(p.read_bytes()) != sha(candidate):
                    raise RuntimeError('Verificación posterior falló: ' + name)
        except Exception:
            for p, original, mode in reversed(completed):
                if original is None:
                    p.unlink()
                else:
                    atomic(p, original, mode)
            raise
        print('APLICADO: sidebar plegable. Sin SQL ni cambios en telefonía o permisos. RESPALDO: ' + str(backup))


if __name__ == '__main__':
    try:
        main()
    except Exception as error:
        print('ERROR: ' + str(error), file=sys.stderr)
        sys.exit(1)
