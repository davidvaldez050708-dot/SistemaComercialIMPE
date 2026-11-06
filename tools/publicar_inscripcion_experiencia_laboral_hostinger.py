#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Experiencia Laboral A286: habilita únicamente el contrato ya aprobado.
Un archivo PHP; --check sin escrituras / --apply con respaldo / --restore.
No ejecuta SQL, no modifica datos ni reemplaza otros programas o permisos.
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

FILE = 'app/services/FormularioInscripcionValidacion.php'
BEFORE_BLOB = 'bbe7e650c264bfc17227d3472711396003c48944'
AFTER_BLOB = 'e8067b84a09e651347008db1f0ed6f36cb34cf83'


def digest(data):
    return hashlib.sha256(data).hexdigest()


def git_blob(data):
    header = ('blob %d\0' % len(data)).encode('ascii')
    return hashlib.sha1(header + data).hexdigest()


def authorize_site(site, name):
    dest = site / name
    if site not in dest.resolve().parents or dest.is_symlink():
        raise RuntimeError('Ruta no autorizada: ' + name)
    return dest


def authorize_backup(site, backup):
    if site == backup or site in backup.parents or backup in site.parents:
        raise RuntimeError('El respaldo debe permanecer fuera de public_html')


def check(site, source):
    if not (site / 'index.php').is_file():
        raise RuntimeError('No se identificó la instalación del CRM')
    if not source.is_file() or source.is_symlink():
        raise RuntimeError('Falta el servicio aprobado desde GitHub')
    target = source.read_bytes()
    if git_blob(target) != AFTER_BLOB:
        raise RuntimeError('ERROR DE INTEGRIDAD en servicio descargado')
    php = subprocess.run(['php', '-l', str(source)],
                         stdout=subprocess.PIPE, stderr=subprocess.PIPE)
    if php.returncode != 0:
        raise RuntimeError('Error de sintaxis PHP en archivo aprobado')
    dest = authorize_site(site, FILE)
    if not dest.is_file():
        raise RuntimeError('No existe el validador actual de Inscripción')
    original = dest.read_bytes()
    current = git_blob(original)
    if current == AFTER_BLOB:
        print('YA INSTALADO: ' + FILE)
    elif current == BEFORE_BLOB:
        print('COMPATIBLE EXACTO: ' + FILE)
    else:
        raise RuntimeError('CONFLICTO de personalización: ' + FILE + '; sitio intacto')
    print('CHECK OK: habilitación aislada de Titulación por experiencia laboral; 1 PHP')
    print('Sin SQL, Registro, permisos, perfiles, aviso de privacidad ni comprobantes alterados')
    return dest, original, target, stat.S_IMODE(dest.stat().st_mode)


def atomic(dest, content, mode):
    fd, name = tempfile.mkstemp(prefix='.impe-experiencia-laboral-', dir=str(dest.parent))
    try:
        with os.fdopen(fd, 'wb') as out:
            out.write(content)
            out.flush()
            os.fsync(out.fileno())
        os.chmod(name, mode)
        os.replace(name, str(dest))
    finally:
        if os.path.exists(name):
            os.unlink(name)


def apply(site, backup, source):
    authorize_backup(site, backup)
    dest, before, after, mode = check(site, source)
    if before == after:
        print('YA INSTALADO: sin escrituras')
        return
    backup.mkdir(parents=True, exist_ok=True, mode=0o700)
    os.chmod(str(backup), 0o700)
    folder = Path(tempfile.mkdtemp(prefix=datetime.datetime.utcnow().strftime('%Y%m%d-%H%M%S-'),
                                   dir=str(backup)))
    os.chmod(str(folder), 0o700)
    saved = folder / 'FormularioInscripcionValidacion.php.original'
    saved.write_bytes(before)
    os.chmod(str(saved), 0o600)
    manifest = {
        'site': str(site),
        'file': FILE,
        'old_sha256': digest(before),
        'new_sha256': digest(after),
        'mode': mode
    }
    meta = folder / 'respaldo.json'
    meta.write_text(json.dumps(manifest, indent=2), encoding='utf-8')
    os.chmod(str(meta), 0o600)
    if dest.read_bytes() != before:
        raise RuntimeError('CONFLICTO: el servicio cambió antes de publicar')
    atomic(dest, after, mode)
    if dest.read_bytes() != after:
        # Sólo restaurar si no hubo una modificación ajena concurrente.
        if git_blob(dest.read_bytes()) == AFTER_BLOB:
            atomic(dest, before, mode)
        raise RuntimeError('ERROR: verificar respaldo antes de continuar')
    print('APLICADO: experiencia laboral puede presentar y aceptar contrato A286')
    print('RESPALDO: ' + str(folder))


def restore(site, backup, folder):
    authorize_backup(site, backup)
    folder = Path(folder).resolve()
    if backup not in folder.parents:
        raise RuntimeError('Respaldo fuera de ubicación privada autorizada')
    meta = folder / 'respaldo.json'
    if not meta.is_file() or meta.is_symlink():
        raise RuntimeError('No existe un manifiesto íntegro de respaldo')
    manifest = json.loads(meta.read_text(encoding='utf-8'))
    if manifest.get('site') != str(site) or manifest.get('file') != FILE:
        raise RuntimeError('El respaldo pertenece a otra instalación')
    dest = authorize_site(site, FILE)
    current = dest.read_bytes()
    if digest(current) != manifest['new_sha256'] or git_blob(current) != AFTER_BLOB:
        raise RuntimeError('CONFLICTO: servicio modificado desde la publicación')
    source = folder / 'FormularioInscripcionValidacion.php.original'
    if not source.is_file() or source.is_symlink():
        raise RuntimeError('Falta el original del respaldo')
    old = source.read_bytes()
    if digest(old) != manifest['old_sha256'] or git_blob(old) != BEFORE_BLOB:
        raise RuntimeError('Integridad del respaldo incorrecta')
    atomic(dest, old, int(manifest['mode']))
    print('RESTAURADO: validador de Inscripción anterior; sin SQL')


def main():
    if sys.version_info < (3, 6):
        raise RuntimeError('Se requiere Python 3.6 o posterior')
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--site', required=True)
    parser.add_argument('--source', required=True)
    parser.add_argument('--backup-root', required=True)
    actions = parser.add_mutually_exclusive_group(required=True)
    actions.add_argument('--check', action='store_true')
    actions.add_argument('--apply', action='store_true')
    actions.add_argument('--restore')
    args = parser.parse_args()
    site = Path(args.site).resolve()
    backup = Path(args.backup_root).resolve()
    if args.check:
        check(site, Path(args.source).resolve())
    elif args.apply:
        apply(site, backup, Path(args.source).resolve())
    else:
        restore(site, backup, args.restore)


if __name__ == '__main__':
    try:
        main()
    except Exception as error:
        print('ERROR: ' + str(error), file=sys.stderr)
        sys.exit(1)
