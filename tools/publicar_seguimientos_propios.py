#!/usr/bin/env python3
"""Actualización selectiva con contextos exactos, respaldo privado y restauración.

No conecta a MySQL ni carga configuración del CRM. --check no modifica el sitio.
"""
import argparse
import hashlib
import json
import os
from pathlib import Path
import shutil
import subprocess
import tempfile

FILES = (
    'app/helpers/SeguimientoTerritorialScope.php',
    'app/controllers/SeguimientoVinculacionController.php',
    'app/models/OficioVinculacionModel.php',
    'app/models/SeguimientoVinculacionModel.php',
    'app/services/SeguimientoExpedienteService.php',
    'app/views/seguimiento_vinculacion/estado.php',
)


def digest(data):
    return hashlib.sha256(data).hexdigest()


def target(root, relative):
    path = (root / relative).resolve()
    if root not in path.parents:
        raise RuntimeError('Ruta fuera del sitio: ' + relative)
    return path


def patch(data, row):
    crlf = b'\r\n' in data
    text = data.replace(b'\r\n', b'\n').decode('utf-8')
    for index, hunk in enumerate(row['hunks'], 1):
        before, after = hunk['before'], hunk['after']
        if text.count(after) == 1:
            continue
        if text.count(before) != 1:
            raise RuntimeError('Versión diferente: ' + row['path'] +
                               ' (bloque ' + str(index) + '). Sitio intacto; comparar antes de aplicar.')
        text = text.replace(before, after, 1)
    result = text.encode('utf-8')
    return result.replace(b'\n', b'\r\n') if crlf else result


def write(path, data, mode):
    fd, name = tempfile.mkstemp(prefix='.impe-propios-', dir=str(path.parent))
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


def lint(data, label):
    php = shutil.which('php')
    if not php:
        raise RuntimeError('PHP es necesario para validar ' + label)
    with tempfile.TemporaryDirectory(prefix='impe-propios-lint-') as directory:
        name = Path(directory) / 'validar.php'
        name.write_bytes(data)
        checked = subprocess.run([php, '-l', str(name)], capture_output=True)
        if checked.returncode:
            raise RuntimeError('No se publicará PHP con errores: ' + label)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--site', required=True)
    parser.add_argument('--backup-root', required=True)
    action = parser.add_mutually_exclusive_group(required=True)
    action.add_argument('--check', action='store_true')
    action.add_argument('--apply', action='store_true')
    action.add_argument('--restore')
    args = parser.parse_args()
    root, backups = Path(args.site).resolve(), Path(args.backup_root).resolve()
    if not (root / 'index.php').is_file():
        raise RuntimeError('No es la raíz del CRM')
    if backups == root or root in backups.parents:
        raise RuntimeError('El respaldo debe quedar fuera del sitio público')
    spec = json.loads(Path(__file__).with_name('seguimientos_propios_release.json').read_text(encoding='utf-8'))
    if tuple(row['path'] for row in spec['files']) != FILES:
        raise RuntimeError('Lista de archivos inválida')
    plan = []
    for row in spec['files']:
        path = target(root, row['path'])
        old = path.read_bytes()
        new = patch(old, row)
        lint(new, row['path'])
        if new != old:
            plan.append((row['path'], path, old, new, path.stat().st_mode & 0o777))
    if args.restore:
        backup = Path(args.restore).resolve()
        if backups not in backup.parents:
            raise RuntimeError('Respaldo fuera de la ubicación autorizada')
        record = json.loads((backup / 'manifest.json').read_text(encoding='utf-8'))
        plan = []
        if record['release'] != spec['release']:
            raise RuntimeError('Respaldo de otra entrega')
        names = [row['path'] for row in record['files']]
        if len(names) != len(set(names)) or not set(names).issubset(FILES):
            raise RuntimeError('Respaldo inválido')
        for row in record['files']:
            path = target(root, row['path'])
            current, original = path.read_bytes(), target(backup, row['path']).read_bytes()
            if digest(original) != row['old_sha']:
                raise RuntimeError('Respaldo alterado: ' + row['path'])
            if current == original:
                continue
            if digest(current) != row['new_sha']:
                raise RuntimeError('Cambios posteriores; restauración bloqueada: ' + row['path'])
            plan.append((row['path'], path, current, original, row['mode']))
    if args.check:
        print('COMPROBACION_OK: ' + str(len(plan)) + ' archivos por actualizar; sitio intacto.')
        return
    if not plan:
        print('SIN_CAMBIOS: la versión solicitada ya está aplicada.')
        return
    if not args.restore:
        backups.mkdir(parents=True, exist_ok=True, mode=0o700)
        backup = Path(tempfile.mkdtemp(prefix='seguimientos-propios-', dir=str(backups)))
        entries = []
        for relative, path, old, new, mode in plan:
            saved = target(backup, relative)
            saved.parent.mkdir(parents=True, exist_ok=True)
            saved.write_bytes(old)
            os.chmod(saved, 0o600)
            entries.append(dict(path=relative, old_sha=digest(old), new_sha=digest(new), mode=mode))
        (backup / 'manifest.json').write_text(json.dumps(dict(release=spec['release'], files=entries)), encoding='utf-8')
        print('RESPALDO: ' + str(backup))
    written = []
    try:
        # Todos los contextos y sintaxis se verifican antes de la primera escritura.
        for relative, path, old, new, mode in plan:
            if path.read_bytes() != old:
                raise RuntimeError('El archivo cambió durante la publicación: ' + relative)
            write(path, new, mode)
            written.append((path, old, mode))
        for relative, path, old, new, mode in plan:
            if path.read_bytes() != new:
                raise RuntimeError('Falló la verificación posterior: ' + relative)
    except BaseException:
        for path, old, mode in reversed(written):
            write(path, old, mode)
        raise
    print(('RESTAURACION_OK' if args.restore else 'PUBLICACION_OK') + ': ' + str(len(plan)) + ' archivos. No se modificaron registros ni responsables.')


if __name__ == '__main__':
    try:
        main()
    except (OSError, ValueError, RuntimeError, KeyError) as error:
        raise SystemExit('BLOQUEADO: ' + str(error))
