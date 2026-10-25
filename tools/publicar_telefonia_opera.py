#!/usr/bin/env python3
"""Instalador reversible de telefonía Opera (Python 3.6+, sin SQL)."""
from __future__ import print_function
import argparse
import hashlib
import json
import os
import shutil
import stat
import subprocess
import sys
import tempfile
import time
from pathlib import Path

def digest(data):
    return hashlib.sha256(data).hexdigest()

def abort(reason):
    raise RuntimeError(reason)

def atomic(path, payload, mode):
    path.parent.mkdir(parents=True, exist_ok=True)
    fd, temp = tempfile.mkstemp(prefix='.impe-telefonia-opera-', dir=str(path.parent))
    try:
        with os.fdopen(fd, 'wb') as out:
            out.write(payload)
            out.flush()
            os.fsync(out.fileno())
        os.chmod(temp, mode)
        os.replace(temp, str(path))
    finally:
        if os.path.exists(temp):
            os.unlink(temp)

def destination(root, relative):
    part = Path(relative)
    if part.is_absolute() or '..' in part.parts:
        abort('Ruta no permitida')
    current = root
    for element in part.parts:
        current = current / element
        if current.is_symlink():
            abort('Enlace simbólico bloqueado: ' + relative)
    if root not in current.resolve().parents:
        abort('La ruta sale del CRM: ' + relative)
    return current

def lint(name, content):
    exe = 'php' if name.endswith('.php') else 'node' if name.endswith('.js') else ''
    tool = shutil.which(exe) if exe else None
    if not tool:
        if exe == 'php':
            abort('PHP CLI necesario para validar el paquete')
        return
    fd, temp = tempfile.mkstemp(suffix=Path(name).suffix)
    try:
        with os.fdopen(fd, 'wb') as out:
            out.write(content)
        command = [tool, '-l' if exe == 'php' else '--check', temp]
        check = subprocess.run(command, stdout=subprocess.PIPE, stderr=subprocess.PIPE)
        if check.returncode:
            abort('Error de sintaxis en ' + name + ': ' + (
                check.stdout + check.stderr).decode('utf-8', 'replace')[-400:])
    finally:
        os.unlink(temp)

def main():
    arg = argparse.ArgumentParser(description=__doc__)
    arg.add_argument('--site', required=True)
    arg.add_argument('--backup-root', required=True)
    arg.add_argument('--release', default=str(Path(__file__).with_name('telefonia_opera_release.json')))
    exclusive = arg.add_mutually_exclusive_group(required=True)
    exclusive.add_argument('--check', action='store_true')
    exclusive.add_argument('--apply', action='store_true')
    exclusive.add_argument('--restore')
    opt = arg.parse_args()
    site = Path(opt.site).resolve()
    backups = Path(opt.backup_root).resolve()
    if not site.is_dir() or not (site / 'index.php').is_file():
        abort('No se reconoce la raíz del CRM.')
    if backups == site or site in backups.parents or backups in site.parents:
        abort('El respaldo debe estar fuera de public_html.')
    release_file = Path(opt.release)
    content = release_file.read_bytes()
    spec = json.loads(content.decode('utf-8'))
    files = spec.get('files', [])
    allowed = set([
        'public/javascript/telefonia_shell.js',
        'public/javascript/telefonia_host.js',
        'app/views/telefonia/shell.php',
        'public/css/telefonia_shell.css'
    ])
    if spec.get('version') != 1 or len(files) != 4 or set(
            entry.get('path') for entry in files) != allowed:
        abort('Manifiesto inesperado; no se modifica el sitio.')

    if opt.restore:
        folder = Path(opt.restore).resolve()
        if backups not in folder.parents or not folder.is_dir() or folder.is_symlink():
            abort('Respaldo fuera del directorio permitido')
        journal = folder / 'manifest.json'
        if not journal.is_file() or journal.is_symlink():
            abort('No se encontró el manifiesto del respaldo.')
        info = json.loads(journal.read_text(encoding='utf-8'))
        if info.get('release_sha') != digest(content):
            abort('El respaldo pertenece a otra publicación')
        records = info.get('files', [])
        if not records or len(records) > 4 or not set(
                r.get('path') for r in records).issubset(allowed):
            abort('Listado del respaldo no válido')
        restore = []
        for entry in records:
            name = entry['path']
            target = destination(site, name)
            stored = destination(folder, name)
            if not stored.is_file() or digest(stored.read_bytes()) != entry['old_sha']:
                abort('Respaldo alterado: ' + name)
            current = target.read_bytes() if target.exists() else None
            if current != stored.read_bytes() and digest(current or b'') != entry['new_sha']:
                abort('Cambio posterior detectado: ' + name)
            restore.append((target, current, stored.read_bytes(), entry['mode']))
        written = []
        try:
            for path, current, previous, mode in restore:
                if (path.read_bytes() if path.exists() else None) != current:
                    abort('El archivo cambió durante la restauración')
                if current == previous:
                    continue
                atomic(path, previous, mode)
                written.append((path, current, mode))
        except Exception:
            for path, original, mode in reversed(written):
                if original is not None:
                    atomic(path, original, mode)
            raise
        print('RESTAURADO: telefonía Opera y enlaces previos. Sin SQL.')
        return

    planned = []
    for entry in files:
        name = entry['path']
        target = destination(site, name)
        if not target.is_file():
            abort('Archivo requerido inexistente: ' + name)
        original = target.read_bytes()
        crlf = b'\r\n' in original
        if crlf and original.count(b'\r\n') != original.count(b'\n'):
            abort('Archivo con saltos de línea mixtos: ' + name)
        try:
            source = original.decode('utf-8').replace('\r\n', '\n')
        except UnicodeDecodeError:
            abort('El archivo no usa UTF-8: ' + name)
        updated = source
        for before, after in entry['patches']:
            if after and updated.count(after) == 1:
                continue
            if updated.count(before) != 1:
                abort('CONFLICTO: personalización distinta o fragmento ausente: ' + name)
            updated = updated.replace(before, after, 1)
        new_data = updated.replace('\n', '\r\n').encode('utf-8') if crlf else updated.encode('utf-8')
        if new_data == original:
            print('ACTUALIZADO: ' + name)
            continue
        lint(name, new_data)
        planned.append((name, target, original, new_data, stat.S_IMODE(target.stat().st_mode)))
        print('COMPATIBLE: ' + name + ' ' + digest(original)[:12] + ' -> ' + digest(new_data)[:12])
    if opt.check:
        print('CHECK OK: sin modificaciones ni respaldo creado.')
        return
    if not planned:
        print('SIN CAMBIOS: paquete ya publicado.')
        return
    backups.mkdir(parents=True, exist_ok=True)
    os.chmod(str(backups), 0o700)
    folder = Path(tempfile.mkdtemp(prefix=time.strftime('%Y%m%d-%H%M%S-'), dir=str(backups)))
    os.chmod(str(folder), 0o700)
    record = {'release_sha': digest(content), 'files': []}
    for name, target, old, new, mode in planned:
        atomic(destination(folder, name), old, 0o600)
        record['files'].append({'path': name, 'old_sha': digest(old),
                                'new_sha': digest(new), 'mode': mode})
    atomic(folder / 'manifest.json', json.dumps(record, indent=2).encode('utf-8'), 0o600)
    for name, target, old, new, mode in planned:
        if target.read_bytes() != old:
            abort('Archivo modificado antes de publicar: ' + name)
    written = []
    try:
        for name, target, old, new, mode in planned:
            if target.read_bytes() != old:
                abort('El archivo cambió durante la publicación: ' + name)
            atomic(target, new, mode)
            written.append((target, old, mode))
    except Exception:
        for target, previous, mode in reversed(written):
            atomic(target, previous, mode)
        raise
    print('APLICADO. RESPALDO: ' + str(folder))
    print('Sin SQL, sin credenciales y sin cambios a grabaciones ni llamadas.')

if __name__ == '__main__':
    try:
        main()
    except Exception as error:
        print('ERROR: ' + str(error), file=sys.stderr)
        sys.exit(1)
