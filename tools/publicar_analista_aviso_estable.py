#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Actualización selectiva del aviso de resultado de llamada en Seguimiento.
Sólo dos archivos JS; no cambia llamadas, BD, grabaciones ni registros.
"""
from __future__ import print_function
import argparse
import contextlib
import datetime
import hashlib
import json
import os
from pathlib import Path
import shutil
import subprocess
import sys
import tempfile

FILES = {
    'public/javascript/seguimiento_llamada_registro_obligatorio.js': {
        'before': '475f62468bcf903e83baecfe0628a0a8d1b36f5c',
        'after': '198cd72f0398b63854aca98c4299d042ca0591aa',
    },
    'public/javascript/seguimiento_llamada_registro_compacto.js': {
        'before': 'f4809baac03791ba230727fab4cc7bde5da57efb',
        'after': '82f2d09f96704b08767c29d77e853e1d35e53630',
    },
}

def digest(data):
    return None if data is None else hashlib.sha256(data).hexdigest()

def blob(data):
    return None if data is None else hashlib.sha1(
        ('blob ' + str(len(data)) + '\0').encode('ascii') + data).hexdigest()

def read(path):
    return path.read_bytes() if path.is_file() else None

def safe(root, name):
    if Path(name).is_absolute() or '..' in Path(name).parts or '\\' in name:
        raise RuntimeError('Ruta inválida: ' + name)
    dest = root / name
    if root not in dest.resolve().parents:
        raise RuntimeError('Ruta fuera del sitio: ' + name)
    node = dest
    while node != root:
        if node.is_symlink():
            raise RuntimeError('No se permite enlace simbólico: ' + name)
        node = node.parent
    return dest

def prepare(site, payload):
    rows = []
    for name, expected in FILES.items():
        dest = safe(site, name)
        before = read(dest)
        target = read(safe(payload, Path(name).name))
        if blob(target) != expected['after']:
            raise RuntimeError('PAQUETE ALTERADO: ' + name + '. Sitio intacto.')
        if blob(before) not in (expected['before'], expected['after']):
            raise RuntimeError('VERSIÓN DIFERENTE: ' + name + '. Sitio intacto.')
        mode = dest.stat().st_mode & 0o777
        row = {'name': name, 'dest': dest, 'before': before, 'after': target,
               'mode': mode, 'after_mode': 0o644}
        rows.append(row)
        print(('YA INSTALADO: ' if before == target and mode == 0o644
               else 'COMPATIBLE: ') + name)
    node = shutil.which('node')
    if node:
        for row in rows:
            with tempfile.TemporaryDirectory(prefix='impe-aviso-sintaxis-') as temp:
                target = Path(temp) / 'archivo.js'
                target.write_bytes(row['after'])
                proc = subprocess.run([node, '--check', str(target)],
                                      stdout=subprocess.PIPE, stderr=subprocess.PIPE)
                if proc.returncode:
                    raise RuntimeError('Sintaxis JS inválida: ' + row['name'])
    else:
        print('AVISO: Node.js no disponible; la sintaxis se valida en GitHub Actions.')
    return rows

def atomic(dest, data, mode):
    fd, path = tempfile.mkstemp(prefix='.impe-aviso-llamada-', dir=str(dest.parent))
    try:
        with os.fdopen(fd, 'wb') as out:
            out.write(data)
            out.flush()
            os.fsync(out.fileno())
        os.chmod(path, mode)
        os.replace(path, str(dest))
    finally:
        if os.path.exists(path):
            os.unlink(path)

@contextlib.contextmanager
def lock(root):
    root.mkdir(mode=0o700, parents=True, exist_ok=True)
    path = root / '.impe-aviso-estable.lock'
    fd = os.open(str(path), os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600)
    try:
        os.write(fd, str(os.getpid()).encode('ascii'))
        os.close(fd)
        yield
    finally:
        path.unlink()

def apply(site, root, payload):
    with lock(root):
        rows = prepare(site, payload)
        changed = [x for x in rows if x['before'] != x['after'] or x['mode'] != 0o644]
        if not changed:
            print('YA INSTALADO: sin cambios.')
            return
        folder = Path(tempfile.mkdtemp(
            prefix=datetime.datetime.utcnow().strftime('%Y%m%d-%H%M%S-'), dir=str(root)))
        os.chmod(str(folder), 0o700)
        meta = {'site': str(site), 'files': []}
        for row in changed:
            original = safe(folder, 'original/' + row['name'])
            original.parent.mkdir(parents=True, exist_ok=True)
            original.write_bytes(row['before'])
            meta['files'].append({'name': row['name'], 'before': digest(row['before']),
                                  'after': digest(row['after']),
                                  'before_mode': row['mode'], 'after_mode': row['after_mode']})
        (folder/'respaldo.json').write_text(json.dumps(meta, indent=2), encoding='utf-8')
        for row in changed:
            if read(row['dest']) != row['before'] or row['dest'].stat().st_mode & 0o777 != row['mode']:
                raise RuntimeError('CAMBIO CONCURRENTE: ' + row['name'] + '. Sin escrituras.')
        written = []
        try:
            for row in changed:
                if read(row['dest']) != row['before']:
                    raise RuntimeError('Archivo cambiado durante la publicación: ' + row['name'])
                atomic(row['dest'], row['after'], row['after_mode'])
                written.append(row)
                if read(row['dest']) != row['after']:
                    raise RuntimeError('Falló la validación posterior de ' + row['name'])
        except BaseException:
            for row in reversed(written):
                if read(row['dest']) == row['after']:
                    atomic(row['dest'], row['before'], row['mode'])
                else:
                    print('REVISAR: cambio externo en ' + row['name'], file=sys.stderr)
            raise
        print('APLICADO: aviso estable y discreto del Panel de trabajo.')
        print('RESPALDO: ' + str(folder))
        print('SIN SQL; sin cambios en llamadas, grabaciones ni formularios.')

def restore(site, root, folder_name):
    folder = Path(folder_name).resolve()
    if root not in folder.parents or site == folder or site in folder.parents:
        raise RuntimeError('Respaldo fuera del directorio privado.')
    with lock(root):
        data = json.loads((folder/'respaldo.json').read_text(encoding='utf-8'))
        if data.get('site') != str(site) or not data.get('files'):
            raise RuntimeError('Respaldo ajeno.')
        rows = []
        for item in data['files']:
            if item['name'] not in FILES:
                raise RuntimeError('Archivo no autorizado en el respaldo.')
            dest = safe(site, item['name'])
            original = read(safe(folder, 'original/' + item['name']))
            if digest(original) != item['before'] or digest(read(dest)) != item['after']:
                raise RuntimeError('Versión modificada o respaldo dañado: ' + item['name'])
            if dest.stat().st_mode & 0o777 != item['after_mode']:
                raise RuntimeError('Permisos cambiados: ' + item['name'])
            rows.append((dest, original, item['before_mode']))
        for dest, original, mode in reversed(rows):
            atomic(dest, original, mode)
        print('RESTAURADO: se recuperaron los dos archivos originales. Sin SQL.')

def main():
    if sys.version_info < (3, 6):
        raise RuntimeError('Se requiere Python 3.6 o posterior.')
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--site', required=True)
    parser.add_argument('--backup-root', required=True)
    parser.add_argument('--payload', default=str(Path(__file__).parent))
    action = parser.add_mutually_exclusive_group(required=True)
    action.add_argument('--check', action='store_true')
    action.add_argument('--apply', action='store_true')
    action.add_argument('--restore')
    args = parser.parse_args()
    site = Path(args.site).resolve()
    root = Path(args.backup_root).resolve()
    payload = Path(args.payload).resolve()
    if not (site/'index.php').is_file():
        raise RuntimeError('No se encontró el CRM en --site.')
    if site == root or site in root.parents:
        raise RuntimeError('El respaldo debe estar fuera del sitio.')
    if args.restore:
        restore(site, root, args.restore)
    elif args.apply:
        apply(site, root, payload)
    else:
        prepare(site, payload)
        print('CHECK OK: dos archivos; sin escrituras ni SQL.')

if __name__ == '__main__':
    try:
        main()
    except Exception as err:
        print('ERROR: ' + str(err), file=sys.stderr)
        sys.exit(1)
