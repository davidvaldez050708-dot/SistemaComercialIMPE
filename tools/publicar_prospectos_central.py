#!/usr/bin/env python3
"""Publicación selectiva offline. No ejecuta SQL ni modifica servicios externos."""
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

FILES = {
    'app/controllers/ProspectoController.php', 'app/models/ProspectoModel.php',
    'app/models/FormularioRegistroModel.php', 'app/views/prospectos/index.php',
    'app/helpers/ProspectoCentralDatos.php',
    'app/services/ProspectoCentralSchema.php', 'app/services/ProspectoCentralRepository.php',
    'app/services/ProspectoCentralIncorporacionService.php',
    'app/views/prospectos/central.php', 'app/views/prospectos/detalle.php',
    'app/views/prospectos/partials/navegacion.php', 'app/views/prospectos/partials/resumen.php',
    'app/views/prospectos/partials/paginacion_central.php',
    'public/css/prospectos_central.css', 'public/javascript/prospectos_central.js',
    'database/migrations/2026_11_02_prospectos_almacen_central.sql', 'tools/prospectos_almacen.php'
}


def sha(data):
    return hashlib.sha256(data).hexdigest()


def target(site, name):
    relative = Path(name)
    if relative.is_absolute() or '..' in relative.parts or '\\' in name:
        raise RuntimeError('Ruta no válida.')
    path = site / relative
    if site not in path.resolve().parents or path.is_symlink():
        raise RuntimeError('Ruta fuera del sitio: ' + name)
    return path


def read(path):
    return path.read_bytes() if path.is_file() else None


def atomic(path, data, mode=0o644):
    path.parent.mkdir(parents=True, exist_ok=True)
    fd, name = tempfile.mkstemp(prefix='.prospectos-', dir=str(path.parent))
    try:
        with os.fdopen(fd, 'wb') as stream:
            stream.write(data)
            stream.flush()
            os.fsync(stream.fileno())
        os.chmod(name, mode)
        os.replace(name, str(path))
    finally:
        if os.path.exists(name):
            os.unlink(name)


def restore(site, backup):
    info = json.loads((backup / 'respaldo.json').read_text(encoding='utf-8'))
    if info['site'] != str(site) or not set(r['path'] for r in info['files']).issubset(FILES):
        raise RuntimeError('Respaldo no válido para este sitio.')
    plan = []
    for row in info['files']:
        path = target(site, row['path'])
        current = read(path)
        if current is None or sha(current) != row['installed']:
            raise RuntimeError('Archivo modificado después de publicar: ' + row['path'])
        original = read(target(backup / 'original', row['path'])) if row['before'] else None
        if row['before'] and (original is None or sha(original) != row['before']):
            raise RuntimeError('Respaldo incompleto: ' + row['path'])
        plan.append((path, original, row['mode']))
    for path, original, mode in reversed(plan):
        if original is None:
            path.unlink()
        else:
            atomic(path, original, mode)
    print('RESTAURADO: código anterior. Las tablas y los registros comerciales se conservaron; no se ejecutó SQL.')


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--site', required=True)
    parser.add_argument('--manifest', default=str(Path(__file__).with_name('prospectos_central_release.json')))
    parser.add_argument('--backup-root', default=str(Path.home() / 'respaldos-impe-prospectos-central'))
    mode = parser.add_mutually_exclusive_group(required=True)
    mode.add_argument('--check', action='store_true')
    mode.add_argument('--apply', action='store_true')
    mode.add_argument('--restore')
    args = parser.parse_args()
    site = Path(args.site).resolve()
    if not (site / 'index.php').is_file():
        raise RuntimeError('Sitio no válido.')
    private = Path(args.backup_root).resolve()
    if private == site or site in private.parents:
        raise RuntimeError('Los respaldos deben permanecer fuera del sitio público.')
    if args.restore:
        backup = Path(args.restore).resolve()
        if backup == site or site in backup.parents:
            raise RuntimeError('El respaldo no debe estar dentro del sitio público.')
        restore(site, backup)
        return
    manifest = json.loads(Path(args.manifest).read_text(encoding='utf-8'))
    if {r['path'] for r in manifest['files']} != FILES or len(manifest['files']) != len(FILES):
        raise RuntimeError('La entrega contiene archivos fuera del alcance autorizado.')
    if not shutil.which('php'):
        raise RuntimeError('Se requiere PHP CLI para verificar la sintaxis.')
    deps = []
    for dep in manifest['dependencies']:
        path = target(site, dep['path'])
        data = read(path)
        if data is None or sha(data) != dep['sha256']:
            raise RuntimeError('DEPENDENCIA DIFERENTE: ' + dep['path'] + '. Sitio intacto; comparar antes de publicar.')
        deps.append((path, data))
    plan = []
    with tempfile.TemporaryDirectory(prefix='revision-prospectos-') as temp:
        for row in manifest['files']:
            name = row['path']
            path = target(site, name)
            original = read(path)
            desired = gzip.decompress(base64.b64decode(row['content']))
            if sha(desired) != row['sha256']:
                raise RuntimeError('Entrega corrupta: ' + name)
            if original == desired:
                print('SIN CAMBIOS: ' + name)
                continue
            actual = sha(original) if original is not None else None
            if actual != row['before']:
                raise RuntimeError('VERSIÓN DIFERENTE: ' + name + '. Sitio intacto; no se sustituirán personalizaciones desconocidas.')
            staged = Path(temp) / name
            staged.parent.mkdir(parents=True, exist_ok=True)
            staged.write_bytes(desired)
            command = ['php', '-l', str(staged)] if name.endswith('.php') else (['node', '--check', str(staged)] if name.endswith('.js') and shutil.which('node') else None)
            if command and subprocess.run(command, stdout=subprocess.PIPE, stderr=subprocess.PIPE).returncode:
                raise RuntimeError('Sintaxis inválida: ' + name)
            plan.append((name, path, original, desired, (path.stat().st_mode & 0o777) if original is not None else 0o644))
            print('COMPATIBLE: ' + name)
    if args.check:
        print('CHECK OK: versiones y sintaxis verificadas. Sitio intacto; sin SQL.')
        return
    if not plan:
        print('Sin cambios: la entrega ya está instalada. Sin SQL.')
        return
    for path, data in deps:
        if read(path) != data:
            raise RuntimeError('Dependencia modificada durante la revisión. Sitio intacto.')
    for name, path, original, desired, mode in plan:
        if read(path) != original:
            raise RuntimeError('Cambio concurrente: ' + name)
    # Instalar dependencias nuevas antes de activar controladores y el hook.
    plan.sort(key=lambda row: row[2] is not None)
    private.mkdir(parents=True, exist_ok=True)
    os.chmod(str(private), 0o700)
    backup = Path(tempfile.mkdtemp(prefix=datetime.datetime.utcnow().strftime('%Y%m%d-%H%M%S-'), dir=str(private)))
    info = {'site': str(site), 'files': []}
    for name, path, original, desired, mode in plan:
        if original is not None:
            atomic(target(backup / 'original', name), original, mode)
        info['files'].append({'path': name, 'before': sha(original) if original is not None else None, 'installed': sha(desired), 'mode': mode})
    atomic(backup / 'respaldo.json', json.dumps(info, indent=2).encode('utf-8'), 0o600)
    completed = []
    try:
        for name, path, original, desired, mode in plan:
            if read(path) != original:
                raise RuntimeError('Cambio concurrente: ' + name)
            atomic(path, desired, mode)
            completed.append((path, original, mode))
            if read(path) != desired:
                raise RuntimeError('Verificación posterior fallida: ' + name)
    except Exception:
        for path, original, mode in reversed(completed):
            if original is None:
                path.unlink()
            else:
                atomic(path, original, mode)
        raise
    print('APLICADO: código de Prospectos Fase 1. No se ejecutó SQL ni conciliación. RESPALDO: ' + str(backup))


if __name__ == '__main__':
    try:
        main()
    except Exception as error:
        print('ERROR: ' + str(error), file=sys.stderr)
        sys.exit(1)
