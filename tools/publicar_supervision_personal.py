#!/usr/bin/env python3
"""Publicación selectiva y reversible. Sólo comprueba por defecto; no ejecuta SQL."""
import argparse
import base64
import hashlib
import json
import os
from pathlib import Path
import shutil
import subprocess
import tempfile

RELEASE = 'supervision-personal-v1'


def sha(data):
    return hashlib.sha256(data).hexdigest()


def safe(root, name):
    if not isinstance(name, str) or '\\' in name or Path(name).is_absolute():
        raise RuntimeError('Ruta de paquete no válida')
    p = root / name
    if root not in p.resolve().parents or any((root / Path(*Path(name).parts[:n])).is_symlink() for n in range(1, len(Path(name).parts) + 1)):
        raise RuntimeError('Ruta fuera del sitio o enlace simbólico: ' + name)
    return p


def candidate(old, row):
    if row['new_file']:
        new = base64.b64decode(row['content'], validate=True)
        if sha(new) != row['after_sha']:
            raise RuntimeError('Contenido alterado: ' + row['path'])
        if old is not None and old.replace(b'\r\n', b'\n') != new:
            raise RuntimeError('Archivo nuevo ya personalizado: ' + row['path'])
        return old if old is not None else new
    if old is None:
        raise RuntimeError('Falta el archivo instalado: ' + row['path'])
    text = old.replace(b'\r\n', b'\n').decode('utf-8')
    for h in row['hunks']:
        before, after = h['before'], h['after']
        if text.count(after) == 1 and text.replace(after, '', 1).count(before) == 0:
            continue
        if text.count(before) != 1 or text.count(after) != 0:
            raise RuntimeError('Bloque diferente: ' + row['path'] + '. Comparar antes de publicar; sitio intacto.')
        text = text.replace(before, after, 1)
    new = text.encode('utf-8')
    return new.replace(b'\n', b'\r\n') if b'\r\n' in old else new


def lint(data, name):
    ext = Path(name).suffix
    if ext not in ('.php', '.js'):
        return
    exe = shutil.which('php' if ext == '.php' else 'node')
    if not exe:
        if ext == '.php':
            raise RuntimeError('PHP CLI es obligatorio')
        return  # El paquete JS se valida localmente; Node no es requisito del alojamiento PHP.
    with tempfile.TemporaryDirectory(prefix='sp-lint-') as tmp:
        p = Path(tmp) / Path(name).name
        p.write_bytes(data)
        result = subprocess.run([exe, '-l' if ext == '.php' else '--check', str(p)], capture_output=True)
        if result.returncode:
            raise RuntimeError('Sintaxis no válida: ' + name)


def write(p, data, mode):
    p.parent.mkdir(parents=True, exist_ok=True)
    fd, tmp = tempfile.mkstemp(prefix='.sp-publicacion-', dir=p.parent)
    try:
        with os.fdopen(fd, 'wb') as stream:
            stream.write(data)
            stream.flush()
            os.fsync(stream.fileno())
        os.chmod(tmp, mode)
        os.replace(tmp, p)
    finally:
        if os.path.exists(tmp):
            os.unlink(tmp)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--site', required=True)
    parser.add_argument('--backup-root', required=True)
    parser.add_argument('--paquete', choices=['supervision_personal_release.json','supervision_personal_ajustes_release.json'], default='supervision_personal_release.json')
    modes = parser.add_mutually_exclusive_group()
    modes.add_argument('--check', action='store_true')
    modes.add_argument('--apply', action='store_true')
    modes.add_argument('--restore')
    parser.add_argument('--autorizar-publicacion', action='store_true')
    args = parser.parse_args()
    if (args.apply or args.restore) and not args.autorizar_publicacion:
        raise RuntimeError('Falta autorización expresa de publicación o reversión')
    site, backups = Path(args.site).resolve(), Path(args.backup_root).resolve()
    if not (site / 'index.php').is_file():
        raise RuntimeError('No es la raíz del CRM')
    if backups == site or site in backups.parents:
        raise RuntimeError('El respaldo debe estar fuera del sitio público')
    spec = json.loads(Path(__file__).with_name(args.paquete).read_text(encoding='utf-8'))
    names = [row['path'] for row in spec['files']]
    if spec['release'] not in (RELEASE,'supervision-personal-ajustes-v1') or len(names) != len(set(names)) or not names:
        raise RuntimeError('Paquete inválido')
    plan = []
    if args.restore:
        backup = Path(args.restore).resolve()
        if backups not in backup.parents:
            raise RuntimeError('Respaldo fuera de la carpeta autorizada')
        record = json.loads((backup / 'manifest.json').read_text(encoding='utf-8'))
        paths = [r['path'] for r in record['files']]
        if record['release'] != spec['release'] or len(paths) != len(set(paths)) or not set(paths).issubset(names):
            raise RuntimeError('Respaldo inválido')
        for row in reversed(record['files']):
            p = safe(site, row['path'])
            old = p.read_bytes() if p.exists() else None
            new = None if row['old_sha'] is None else safe(backup, row['path']).read_bytes()
            if new is not None and sha(new) != row['old_sha']:
                raise RuntimeError('Respaldo alterado')
            if old == new:
                continue
            if old is None or sha(old) != row['new_sha']:
                raise RuntimeError('Cambios posteriores: ' + row['path'])
            plan.append((row['path'], p, old, new, row['mode']))
    else:
        for row in spec['files']:
            p = safe(site, row['path'])
            old = p.read_bytes() if p.exists() else None
            new = candidate(old, row)
            lint(new, row['path'])
            if old != new:
                plan.append((row['path'], p, old, new, p.stat().st_mode & 0o777 if p.exists() else 0o644))
        if not args.apply:
            print('COMPROBACION_OK: ' + str(len(plan)) + ' archivos; sitio intacto. Sin SQL.')
            return
        if not plan:
            print('SIN_CAMBIOS: paquete ya aplicado.')
            return
        backups.mkdir(parents=True, exist_ok=True, mode=0o700)
        backup = Path(tempfile.mkdtemp(prefix='supervision-personal-', dir=backups))
        os.chmod(backup, 0o700)
        rows = []
        for name, p, old, new, mode in plan:
            if old is not None:
                saved = safe(backup, name)
                saved.parent.mkdir(parents=True, exist_ok=True)
                saved.write_bytes(old)
                os.chmod(saved, 0o600)
            rows.append(dict(path=name, old_sha=None if old is None else sha(old), new_sha=sha(new), mode=mode))
        manifest = backup / 'manifest.json'
        manifest.write_text(json.dumps(dict(release=spec['release'], files=rows)), encoding='utf-8')
        os.chmod(manifest, 0o600)
        print('RESPALDO: ' + str(backup))
    written = []
    try:
        for name, p, old, new, mode in plan:
            if (p.read_bytes() if p.exists() else None) != old:
                raise RuntimeError('Cambio concurrente: ' + name)
            if new is None:
                p.unlink()
            else:
                write(p, new, mode)
            written.append((p, old, new, mode))
        for name, p, old, new, mode in plan:
            if (p.read_bytes() if p.exists() else None) != new:
                raise RuntimeError('Falló la verificación posterior: ' + name)
    except BaseException:
        for p, old, new, mode in reversed(written):
            # No sobrescribir cambios de otro proceso durante la recuperación.
            if (p.read_bytes() if p.exists() else None) != new:
                continue
            if old is None:
                p.unlink()
            else:
                write(p, old, mode)
        raise
    print(('RESTAURACION_OK' if args.restore else 'PUBLICACION_OK') + ': ' + str(len(plan)) + ' archivos. Sin SQL ni activación automática. No se iniciaron llamadas.')


if __name__ == '__main__':
    try:
        main()
    except (OSError, RuntimeError, ValueError, KeyError) as error:
        raise SystemExit('BLOQUEADO: ' + str(error))
