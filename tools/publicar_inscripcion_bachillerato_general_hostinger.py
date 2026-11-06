#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Bachillerato General / Examen Único A286: publisher reversible (6 files).

--check: strict Git blob comparison, syntax, permissions; does not write.
--apply: check again, private backup then atomic replace only whitelisted paths.
--restore BACKUP: verify deployed hashes before restoring; never touch SQL/data.
Compatible with Python 3.6+ on Hostinger.
"""
from __future__ import print_function
import argparse
import datetime
import hashlib
import json
import os
from pathlib import Path
import stat
import subprocess
import sys
import tempfile

FILES = {
    'app/services/FormularioInscripcionValidacion.php': (
        'e8067b84a09e651347008db1f0ed6f36cb34cf83',
        'dc78cdfc24438a6932072bf8e32153e6252099d2'),
    'app/services/FormularioInscripcionPantalla.php': (
        'f5a9f390c8b0c40c4150fc3eb81c0a912792fe63',
        '24f95a2285e54dcddca6284717daacfba973863b'),
    'app/views/formularios/inscripcion_completa.php': (
        '46512703feaee57ca0f27ce9b83a6ffeb76bef46',
        '2c7462072832931e29a30aea34e9384ede0da2dc'),
    'app/views/formularios/publico_inscripcion_completa.php': (
        '26665054d9bdd6cdfe01d9aab8a4b338d0341cb8',
        'a97a8c9594abc07cc51936c69413d07a9197a2c1'),
    'app/resources/inscripcion/terminos-bachillerato-general.txt': (
        None, 'e1965970d7b5f13d995e3cc298ac5189513313dc'),
    'app/resources/inscripcion/privacidad-bachillerato-general.txt': (
        None, '6f40657def9bc526acdd0d4db15574630c395867')
}


def git_blob(data):
    return hashlib.sha1(('blob %d\0' % len(data)).encode('ascii') + data).hexdigest()


def sha256(data):
    return hashlib.sha256(data).hexdigest()


def safe_file(root, rel):
    path = root / rel
    # Resolve also the parent of files not yet created; block symlinks/escapes.
    if path.is_symlink() or root not in path.resolve().parents:
        raise RuntimeError('Ruta fuera del CRM o enlace simbólico: ' + rel)
    return path


def safe_backup(site, backup):
    if backup == site or site in backup.parents or backup in site.parents:
        raise RuntimeError('Respaldo debe estar fuera de public_html')


def check(site, target):
    if not (site / 'index.php').is_file():
        raise RuntimeError('No se encontró el CRM en --site')
    before, after, modes = {}, {}, {}
    for path, (old_blob, new_blob) in FILES.items():
        new_source = safe_file(target, path)
        if not new_source.is_file() or new_source.is_symlink():
            raise RuntimeError('Archivo de destino descargado ausente: ' + path)
        candidate = new_source.read_bytes()
        if git_blob(candidate) != new_blob:
            raise RuntimeError('Integridad GitHub incorrecta: ' + path)
        if path.endswith('.php'):
            syntax = subprocess.run(['php', '-l', str(new_source)],
                                    stdout=subprocess.PIPE, stderr=subprocess.PIPE)
            if syntax.returncode:
                raise RuntimeError('PHP inválido: ' + path)
        dest = safe_file(site, path)
        if old_blob is None:
            if dest.exists():
                if not dest.is_file() or git_blob(dest.read_bytes()) != new_blob:
                    raise RuntimeError('CONFLICTO: existe archivo distinto: ' + path)
                old = dest.read_bytes()
                result = old
                modes[path] = stat.S_IMODE(dest.stat().st_mode)
                print('YA INSTALADO NUEVO: ' + path)
            else:
                old = None
                result = candidate
                modes[path] = 0o644
                print('ARCHIVO NUEVO COMPATIBLE: ' + path)
        else:
            if not dest.is_file():
                raise RuntimeError('Falta archivo de producción: ' + path)
            old = dest.read_bytes()
            current_blob = git_blob(old)
            if current_blob == new_blob:
                print('YA INSTALADO: ' + path)
                result = old
            elif current_blob == old_blob:
                print('COMPATIBLE EXACTO: ' + path)
                result = candidate
            else:
                raise RuntimeError('CONFLICTO de personalizaciones: ' + path +
                                   ' (' + current_blob[:12] + '); sin publicar')
            modes[path] = stat.S_IMODE(dest.stat().st_mode)
        before[path], after[path] = old, result
    print('CHECK OK: Bachillerato General A286 sin Perfil de evaluación; 6 archivos')
    print('Sin SQL, otros programas, Registro, roles ni datos de usuario modificados')
    return before, after, modes


def atomic(path, content, mode):
    fd, name = tempfile.mkstemp(prefix='.bachillerato-general-', dir=str(path.parent))
    try:
        with os.fdopen(fd, 'wb') as stream:
            stream.write(content)
            stream.flush()
            os.fsync(stream.fileno())
        os.chmod(name, mode)
        os.replace(name, str(path))
    finally:
        if os.path.exists(name):
            os.unlink(name)


def apply(site, target, backup):
    safe_backup(site, backup)
    before, after, modes = check(site, target)
    changed = [x for x in FILES if before[x] != after[x]]
    if not changed:
        print('YA INSTALADO: ninguna escritura')
        return
    backup.mkdir(mode=0o700, parents=True, exist_ok=True)
    os.chmod(str(backup), 0o700)
    folder = Path(tempfile.mkdtemp(
        prefix=datetime.datetime.utcnow().strftime('%Y%m%d-%H%M%S-'),
        dir=str(backup)))
    os.chmod(str(folder), 0o700)
    manifest = {'site': str(site), 'files': []}
    for path in changed:
        item = {
            'path': path, 'new': before[path] is None,
            'old_hash': sha256(before[path]) if before[path] is not None else None,
            'new_hash': sha256(after[path]), 'mode': modes[path]
        }
        if before[path] is not None:
            saved = folder / 'original' / path
            saved.parent.mkdir(parents=True, exist_ok=True)
            saved.write_bytes(before[path])
            os.chmod(str(saved), 0o600)
        manifest['files'].append(item)
    meta = folder / 'respaldo.json'
    meta.write_text(json.dumps(manifest, indent=2), encoding='utf-8')
    os.chmod(str(meta), 0o600)

    # Guard against edits between check and apply, before touching any file.
    for path in changed:
        dest = safe_file(site, path)
        old = before[path]
        if (old is None and dest.exists()) or (
            old is not None and (not dest.is_file() or dest.read_bytes() != old)):
            raise RuntimeError('CONFLICTO concurrente: ' + path)

    written = []
    try:
        for path in changed:
            dest = safe_file(site, path)
            dest.parent.mkdir(parents=True, exist_ok=True)
            atomic(dest, after[path], modes[path])
            written.append(path)
            if dest.read_bytes() != after[path]:
                raise RuntimeError('Fallo al verificar publicación: ' + path)
    except BaseException:
        for path in reversed(written):
            dest = safe_file(site, path)
            if dest.is_file() and dest.read_bytes() == after[path]:
                if before[path] is None:
                    dest.unlink()
                else:
                    atomic(dest, before[path], modes[path])
        raise
    print('APLICADO: Bachillerato General, examen único y textos originales')
    print('RESPALDO: ' + str(folder))


def restore(site, backup, folder_name):
    safe_backup(site, backup)
    folder = Path(folder_name).resolve()
    if backup not in folder.parents or not folder.is_dir() or folder.is_symlink():
        raise RuntimeError('Respaldo fuera de carpeta privada')
    meta = folder / 'respaldo.json'
    if not meta.is_file() or meta.is_symlink():
        raise RuntimeError('Manifest de respaldo no encontrado')
    manifest = json.loads(meta.read_text(encoding='utf-8'))
    if manifest.get('site') != str(site):
        raise RuntimeError('Este respaldo pertenece a otro sitio')
    planned = []
    for item in manifest['files']:
        path = item['path']
        if path not in FILES:
            raise RuntimeError('Respaldo incluye ruta no autorizada')
        dest = safe_file(site, path)
        if not dest.is_file() or sha256(dest.read_bytes()) != item['new_hash']:
            raise RuntimeError('CONFLICTO: cambios posteriores en ' + path)
        original = None
        if not item['new']:
            saved = folder / 'original' / path
            if not saved.is_file() or saved.is_symlink():
                raise RuntimeError('Original perdido: ' + path)
            original = saved.read_bytes()
            if sha256(original) != item['old_hash']:
                raise RuntimeError('Respaldo no íntegro: ' + path)
        planned.append((dest, original, int(item['mode'])))
    for dest, original, mode in reversed(planned):
        if original is None:
            dest.unlink()
        else:
            atomic(dest, original, mode)
    print('RESTAURADO: Bachillerato General; sin SQL ni registros alterados')


def main():
    if sys.version_info < (3, 6):
        raise RuntimeError('Se requiere Python 3.6 o posterior')
    p = argparse.ArgumentParser(description=__doc__)
    p.add_argument('--site', required=True)
    p.add_argument('--target-dir', required=True)
    p.add_argument('--backup-root', required=True)
    command = p.add_mutually_exclusive_group(required=True)
    command.add_argument('--check', action='store_true')
    command.add_argument('--apply', action='store_true')
    command.add_argument('--restore')
    a = p.parse_args()
    site = Path(a.site).resolve()
    target = Path(a.target_dir).resolve()
    backup = Path(a.backup_root).resolve()
    if a.check:
        check(site, target)
    elif a.apply:
        apply(site, target, backup)
    else:
        restore(site, backup, a.restore)


if __name__ == '__main__':
    try:
        main()
    except Exception as e:
        print('ERROR: ' + str(e), file=sys.stderr)
        sys.exit(1)
