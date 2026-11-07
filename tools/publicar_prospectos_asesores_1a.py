#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Publicador selectivo Fase 1A de Prospectos. Python 3.6+.
--check: lectura pura. --apply: sólo después de check/autorización.
--restore: exige destino idéntico a esta entrega, nunca pisa personalizaciones.
No ejecuta SQL ni modifica RolModel, telefonía, formularios, prospectos o datos.
"""
import argparse
import base64
import datetime
import hashlib
import json
import os
import shutil
import stat
import sys
import tempfile

MANIFEST = 'prospectos_asesores_1a_release.json'

def die(message):
    raise RuntimeError(message)

def digest(data):
    return hashlib.sha256(data).hexdigest()

def read(path):
    with open(path, 'rb') as fh:
        return fh.read()

def write_atomic(path, data, mode):
    parent = os.path.dirname(path)
    fd, temporary = tempfile.mkstemp(prefix='.impe-asesores-', dir=parent)
    try:
        with os.fdopen(fd, 'wb') as fh:
            fh.write(data)
            fh.flush()
            os.fsync(fh.fileno())
        os.chmod(temporary, mode)
        os.replace(temporary, path)
    finally:
        if os.path.exists(temporary):
            os.unlink(temporary)

def relative(path):
    if not isinstance(path, str) or path.startswith('/') or '\\' in path:
        die('Ruta de manifiesto no permitida.')
    parts = path.split('/')
    if not parts or any(x in ('', '.', '..') for x in parts):
        die('Ruta relativa inválida.')
    return path

def safe_target(site, relative_path):
    dest = os.path.join(site, *relative(relative_path).split('/'))
    sub = site
    for part in relative_path.split('/')[:-1]:
        sub = os.path.join(sub, part)
        if os.path.islink(sub) or not os.path.isdir(sub):
            die('Carpeta inexistente o simbólica: ' + relative_path)
    if os.path.islink(dest) or (os.path.lexists(dest) and not os.path.isfile(dest)):
        die('Archivo no regular o simbólico: ' + relative_path)
    return dest

def sha_at(path):
    return digest(read(path)) if os.path.isfile(path) else None

def inspect(site, manifest):
    problems = []
    states = []
    for item in manifest['dependencies']:
        p = safe_target(site, item['path'])
        actual = sha_at(p)
        if actual != item['sha256']:
            problems.append('Dependencia diferente: ' + item['path'] +
                            ' (esperado ' + item['sha256'] + ', actual ' + str(actual) + ')')
    for item in manifest['files']:
        p = safe_target(site, item['path'])
        actual = sha_at(p)
        status = 'APLICADO' if actual == item['sha256'] else (
            'COMPATIBLE' if actual == item['base_sha256'] else 'CONFLICTO')
        states.append((item['path'], status, actual))
        if status == 'CONFLICTO':
            problems.append('Conflicto: ' + item['path'] +
                            ' (esperado ' + str(item['base_sha256']) +
                            ' o ' + item['sha256'] + ', actual ' + str(actual) + ')')
    return states, problems

def load_manifest(folder):
    manifest = json.loads(read(os.path.join(folder, MANIFEST)).decode('utf-8'))
    if manifest.get('schema') != 1 or manifest.get('package') != 'impe_prospectos_asesores_1a':
        die('Manifiesto inesperado.')
    paths = set()
    for item in manifest['files']:
        path = relative(item['path'])
        if path in paths:
            die('Archivo repetido en manifiesto.')
        paths.add(path)
        raw = base64.b64decode(item['payload_b64'], validate=True)
        if digest(raw) != item['sha256']:
            die('Integridad inválida del archivo: ' + path)
        if item['base_sha256'] is not None and (
                not isinstance(item['base_sha256'], str) or len(item['base_sha256']) != 64):
            die('SHA de origen inválido: ' + path)
        item['_payload'] = raw
    for item in manifest['dependencies']:
        path = relative(item['path'])
        if path in paths:
            die('Una dependencia figura como archivo modificable: ' + path)
        paths.add(path)
    return manifest

def ensure_private_backup(root, site):
    root = os.path.realpath(root)
    if root == site or root.startswith(site + os.sep):
        die('Los respaldos deben almacenarse fuera de public_html.')
    if os.path.islink(root):
        die('Carpeta de respaldo simbólica no permitida.')
    if not os.path.exists(root):
        os.makedirs(root, mode=0o700)
    if not os.path.isdir(root):
        die('Directorio de respaldo inválido.')
    os.chmod(root, 0o700)
    return root

def make_backup(root, manifest, states, site):
    stamp = datetime.datetime.utcnow().strftime('%Y%m%d-%H%M%S')
    folder = tempfile.mkdtemp(prefix='impe-asesores-1a-' + stamp + '-', dir=root)
    os.chmod(folder, 0o700)
    record = {'schema': 1, 'package': manifest['package'], 'files': []}
    for path, status, actual in states:
        item = next(x for x in manifest['files'] if x['path'] == path)
        dest = safe_target(site, path)
        was_present = os.path.isfile(dest)
        original = read(dest) if was_present else b''
        row = {'path':path, 'before_sha256':actual,
               'after_sha256':item['sha256'], 'present':was_present,
               'mode':stat.S_IMODE(os.stat(dest).st_mode) if was_present else 0o644}
        if was_present:
            index = str(len(record['files'])) + '.bak'
            bpath = os.path.join(folder, index)
            with open(bpath, 'wb') as fh:
                fh.write(original)
            os.chmod(bpath, 0o600)
            row['backup_file'] = index
        record['files'].append(row)
    metadata = os.path.join(folder, 'respaldo.json')
    with open(metadata, 'w', encoding='utf-8') as fh:
        json.dump(record, fh, ensure_ascii=False, indent=2)
    os.chmod(metadata, 0o600)
    return folder, record

def restore(site, folder):
    folder = os.path.realpath(folder)
    if folder == site or folder.startswith(site + os.sep):
        die('El respaldo no puede estar dentro del sitio.')
    record = json.loads(read(os.path.join(folder, 'respaldo.json')).decode('utf-8'))
    if record.get('schema') != 1 or record.get('package') != 'impe_prospectos_asesores_1a':
        die('Respaldo ajeno a esta entrega.')
    # Preflight total: ninguna escritura si hay diferencias.
    for row in record['files']:
        path = safe_target(site, row['path'])
        current = sha_at(path)
        if current not in (row['after_sha256'], row['before_sha256']):
            die('Conflicto al restaurar: ' + row['path'])
        if row['present']:
            saved = read(os.path.join(folder, row['backup_file']))
            if digest(saved) != row['before_sha256']:
                die('Respaldo corrupto: ' + row['path'])
    for row in reversed(record['files']):
        path = safe_target(site, row['path'])
        if sha_at(path) == row['before_sha256']:
            continue
        if row['present']:
            write_atomic(path, read(os.path.join(folder, row['backup_file'])), row['mode'])
        elif os.path.isfile(path):
            os.unlink(path)
        print('RESTAURADO:', row['path'])
    print('RESTORE COMPLETO. Sin operaciones SQL.')

def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--site', required=True)
    parser.add_argument('--backup-root')
    group = parser.add_mutually_exclusive_group(required=True)
    group.add_argument('--check', action='store_true')
    group.add_argument('--apply', action='store_true')
    group.add_argument('--restore')
    args = parser.parse_args()
    site = os.path.realpath(args.site)
    if not os.path.isdir(site) or os.path.islink(args.site):
        die('Sitio no encontrado o ruta simbólica.')
    if args.restore:
        restore(site, args.restore)
        return
    manifest = load_manifest(os.path.dirname(os.path.abspath(__file__)))
    states, problems = inspect(site, manifest)
    for path, status, actual in states:
        print('%s: %s' % (status, path))
    for problem in problems:
        print('ERROR:', problem, file=sys.stderr)
    if problems:
        die('Preflight fallido, no se modificó ningún archivo.')
    print('DEPENDENCIAS OK: %s. SQL no ejecutado.' % len(manifest['dependencies']))
    if args.check:
        print('CHECK COMPATIBLE: no se escribió ni se creó respaldo.')
        return
    if not args.backup_root:
        die('Para --apply debes indicar --backup-root fuera del sitio.')
    if all(status == 'APLICADO' for _, status, _ in states):
        print('ALREADY APPLIED: entrega idéntica; sin escrituras.')
        return
    root = ensure_private_backup(args.backup_root, site)
    folder, record = make_backup(root, manifest, states, site)
    print('RESPALDO PRIVADO:', folder)
    touched = []
    try:
        for item in manifest['files']:
            path = safe_target(site, item['path'])
            actual = sha_at(path)
            if actual == item['sha256']:
                continue
            if actual != item['base_sha256']:
                die('Cambio concurrente detectado: ' + item['path'])
            mode = stat.S_IMODE(os.stat(path).st_mode) if os.path.isfile(path) else 0o644
            write_atomic(path, item['_payload'], mode)
            touched.append(item['path'])
            if sha_at(path) != item['sha256']:
                die('Archivo escrito no coincide: ' + item['path'])
        print('PUBLICADO: %s archivos nuevos o actualizados; sin SQL.' % len(touched))
    except Exception:
        # Revertir sólo lo que acaba de escribirse y sólo si sigue idéntico.
        for row in reversed(record['files']):
            if row['path'] not in touched:
                continue
            path = safe_target(site, row['path'])
            if sha_at(path) != row['after_sha256']:
                print('RESTORE MANUAL REQUERIDO:', row['path'], file=sys.stderr)
                continue
            if row['present']:
                write_atomic(path, read(os.path.join(folder,row['backup_file'])), row['mode'])
            else:
                os.unlink(path)
        raise

if __name__ == '__main__':
    try:
        main()
    except Exception as error:
        print('ERROR:', str(error), file=sys.stderr)
        sys.exit(1)
