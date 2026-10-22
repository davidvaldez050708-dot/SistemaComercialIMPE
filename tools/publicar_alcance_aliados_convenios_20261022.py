#!/usr/bin/env python3
"""Entrega selectiva de permisos Aliados/Convenios; NO ejecuta SQL. Python 3.6."""
import argparse
import datetime
import difflib
import hashlib
import json
import os
from pathlib import Path
import shutil
import subprocess
import sys
import tempfile
from urllib.request import urlopen

RAW = "https://raw.githubusercontent.com/davidvaldez050708-dot/SistemaComercialIMPE/"

def digest(data):
    return hashlib.sha256(data).hexdigest()

def blob_sha(data):
    return hashlib.sha1(b'blob ' + str(len(data)).encode('ascii') + b'\0' + data).hexdigest()

def normalized(data):
    return data.replace(b'\r\n', b'\n')

def within(child, parent):
    return child == parent or parent in child.parents

def safe_file(site, name):
    rel = Path(name)
    if rel.is_absolute() or '..' in rel.parts:
        raise RuntimeError('Ruta fuera del sitio: ' + name)
    file_path = site / rel
    if file_path.is_symlink() or not within(file_path.resolve(), site):
        raise RuntimeError('Archivo externo o enlace simbólico: ' + name)
    return file_path

def fetch(ref, name, wanted):
    with urlopen(RAW + ref + '/' + name, timeout=30) as response:
        data = response.read()
    if blob_sha(data) != wanted:
        raise RuntimeError('Hash de GitHub no coincide: ' + name)
    return data

def candidate(current, base, target, stage, name):
    if normalized(current) == normalized(target):
        return current
    if normalized(current) == normalized(base):
        result = normalized(target)
    else:
        for entry, data in [('local', current), ('base', base), ('destino', target)]:
            (stage / entry).write_bytes(normalized(data))
        run = subprocess.run(
            ['git', 'merge-file', '-p', str(stage / 'local'), str(stage / 'base'), str(stage / 'destino')],
            stdout=subprocess.PIPE, stderr=subprocess.PIPE)
        if run.returncode != 0:
            raise RuntimeError('CONFLICTO en ' + name + ': personalización no conciliada; sitio intacto')
        result = run.stdout
    return result.replace(b'\n', b'\r\n') if b'\r\n' in current else result

def atomic(path, data, mode):
    path.parent.mkdir(parents=True, exist_ok=True)
    fd, temp = tempfile.mkstemp(prefix='.alcance-convenios-', dir=str(path.parent))
    try:
        with os.fdopen(fd, 'wb') as stream:
            stream.write(data)
            stream.flush()
            os.fsync(stream.fileno())
        os.chmod(temp, mode)
        os.replace(temp, str(path))
    finally:
        if os.path.exists(temp):
            os.unlink(temp)

def restore(site, location):
    meta = json.loads((location / 'respaldo.json').read_text(encoding='utf-8'))
    if meta['site'] != str(site):
        raise RuntimeError('El respaldo corresponde a otro sitio')
    for row in meta['files']:
        p = safe_file(site, row['path'])
        original = location / 'original' / row['path']
        if not p.is_file() or digest(p.read_bytes()) != row['installed']:
            raise RuntimeError('Se detectaron modificaciones posteriores: ' + row['path'])
        if not original.is_file() or digest(original.read_bytes()) != row['original']:
            raise RuntimeError('Respaldo incompleto: ' + row['path'])
    for row in reversed(meta['files']):
        p = safe_file(site, row['path'])
        atomic(p, (location / 'original' / row['path']).read_bytes(), row['mode'])
    print('RESTAURADO: ocho archivos originales como máximo. Base de datos intacta.')

def main():
    parser = argparse.ArgumentParser(description=__doc__)
    action = parser.add_mutually_exclusive_group(required=True)
    action.add_argument('--check', action='store_true')
    action.add_argument('--apply', action='store_true')
    action.add_argument('--restore', metavar='RUTA')
    parser.add_argument('--site', required=True)
    parser.add_argument('--manifest', required=True)
    parser.add_argument('--backup-root', default=str(Path.home() / 'respaldos-impe-alcance'))
    args = parser.parse_args()
    site = Path(args.site).resolve()
    private = Path(args.backup_root).resolve()
    if not (site / 'index.php').is_file():
        raise RuntimeError('Sitio no válido: falta index.php')
    if within(private, site):
        raise RuntimeError('El respaldo debe quedar fuera de public_html')
    if args.restore:
        location = Path(args.restore).resolve()
        if within(location, site) or not within(location, private):
            raise RuntimeError('Respaldo fuera de ubicación permitida')
        restore(site, location)
        return
    if not shutil.which('git') or not shutil.which('php'):
        raise RuntimeError('Se requieren git y php para validar el despliegue')
    manifest = json.loads(Path(args.manifest).read_text(encoding='utf-8'))
    if len(manifest['files']) != 8:
        raise RuntimeError('El manifiesto no tiene los ocho archivos esperados')
    private.mkdir(parents=True, exist_ok=True)
    os.chmod(str(private), 0o700)
    planned = []
    with tempfile.TemporaryDirectory(prefix='alcance-revision-', dir=str(private)) as name_temp:
        stage = Path(name_temp)
        for row in manifest['files']:
            name = row['path']
            destination = safe_file(site, name)
            if not destination.is_file():
                raise RuntimeError('Falta archivo: ' + name)
            actual = destination.read_bytes()
            original = fetch(manifest['base'], name, row['base_sha'])
            desired = fetch(manifest['source'], name, row['target_sha'])
            reviewed = candidate(actual, original, desired, stage, name)
            if reviewed == actual:
                print('SIN CAMBIOS: ' + name)
                continue
            validation = stage / 'validar.php'
            validation.write_bytes(reviewed)
            check = subprocess.run(['php', '-l', str(validation)],
                                   stdout=subprocess.PIPE, stderr=subprocess.PIPE)
            if check.returncode != 0:
                raise RuntimeError('PHP inválido: ' + name + ': ' +
                                   (check.stderr or check.stdout).decode('utf-8', 'replace'))
            planned.append((name, destination, actual, reviewed, destination.stat().st_mode & 0o777))
            print('PREPARADO: ' + name)
            if args.check:
                diff = difflib.unified_diff(
                    normalized(actual).decode('utf-8', 'replace').splitlines(),
                    normalized(reviewed).decode('utf-8', 'replace').splitlines(),
                    fromfile='Hostinger/' + name, tofile='Revisión/' + name, lineterm='')
                print('\n'.join(diff))
        print('REVISIÓN COMPATIBLE: {} archivos. SQL NO ejecutado.'.format(len(planned)))
        if args.check or not planned:
            print('SITIO INTACTO' if args.check else 'Entrega ya aplicada')
            return
        for name, dest, actual, reviewed, mode in planned:
            if dest.read_bytes() != actual:
                raise RuntimeError('Archivo modificado durante revisión: ' + name)
        backup = private / ('alcance-' + datetime.datetime.utcnow().strftime('%Y%m%d-%H%M%S-%f'))
        backup.mkdir(mode=0o700)
        meta = {'site': str(site), 'release': manifest['source'], 'files': []}
        for name, dest, actual, reviewed, mode in planned:
            saved = backup / 'original' / name
            saved.parent.mkdir(parents=True, exist_ok=True)
            saved.write_bytes(actual)
            meta['files'].append({'path': name, 'original': digest(actual),
                                  'installed': digest(reviewed), 'mode': mode})
        (backup / 'respaldo.json').write_text(json.dumps(meta, indent=2), encoding='utf-8')
        done = []
        try:
            for name, dest, actual, reviewed, mode in planned:
                if dest.read_bytes() != actual:
                    raise RuntimeError('Archivo modificado concurrentemente: ' + name)
                atomic(dest, reviewed, mode)
                done.append((dest, actual, mode))
                if digest(dest.read_bytes()) != digest(reviewed):
                    raise RuntimeError('Hash posterior inválido: ' + name)
        except Exception:
            for dest, original, mode in reversed(done):
                atomic(dest, original, mode)
            raise
        print('PUBLICADO. Respaldo: ' + str(backup))
        print('Base de datos sin cambios. No se alteraron asignaciones ni permisos.')

if __name__ == '__main__':
    try:
        main()
    except Exception as error:
        print('ERROR: ' + str(error), file=sys.stderr)
        sys.exit(1)
