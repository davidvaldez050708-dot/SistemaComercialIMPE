#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Actualiza exclusivamente OficioRedmexPdfRenderer.php. --check no escribe."""
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

PATH = 'app/services/OficioRedmexPdfRenderer.php'
SERVICE = 'app/services/OficioDocxPdfService.php'
BASELINE_SHA256 = '9c82890dcc0fd519634ad60f328da86e588f8d1ebd55a4a133cd30e4d376aee3'
TARGET_BLOB = 'fabab8bcc24b6b6cd1497f105b040ed5720a16a0'


def git_blob(data):
    return hashlib.sha1(('blob %d\0' % len(data)).encode('ascii') + data).hexdigest()


def sha256(data):
    return hashlib.sha256(data).hexdigest()


def atomic(path, data, mode):
    fd, temp = tempfile.mkstemp(prefix='.impe-renderer-', dir=str(path.parent))
    try:
        with os.fdopen(fd, 'wb') as output:
            output.write(data)
            output.flush()
            os.fsync(output.fileno())
        os.chmod(temp, mode)
        os.replace(temp, str(path))
    finally:
        if os.path.exists(temp):
            os.unlink(temp)


def validate_path(site, relative):
    dest = site / relative
    if not dest.is_file() or dest.is_symlink() or site not in dest.resolve().parents:
        raise RuntimeError('Ruta del CRM ausente/no autorizada: ' + relative)
    return dest


def ensure_backup_outside(site, backup):
    if backup == site or backup in site.parents or site in backup.parents:
        raise RuntimeError('La carpeta de respaldo debe estar fuera de public_html')


def preflight(site, source):
    if not (site / 'index.php').is_file():
        raise RuntimeError('Raíz del CRM incorrecta')
    service = validate_path(site, SERVICE).read_bytes()
    if b'OficioRedmexPdfRenderer' not in service:
        raise RuntimeError('CONFLICTO: el servicio de oficios no usa el renderizador REDMEX')
    file = validate_path(site, PATH)
    old = file.read_bytes()
    normalized = old.replace(b'\r\n', b'\n')
    data = source.read_bytes() if source.is_file() and not source.is_symlink() else None
    if data is None or git_blob(data) != TARGET_BLOB:
        raise RuntimeError('INTEGRIDAD: el renderizador descargado no corresponde a GitHub')
    if sha256(normalized) != BASELINE_SHA256 and git_blob(normalized) != TARGET_BLOB:
        raise RuntimeError('CONFLICTO: renderer personalizado o versión desconocida; sitio intacto')
    new = data.replace(b'\n', b'\r\n') if b'\r\n' in old else data
    with tempfile.TemporaryDirectory(prefix='impe-oficio-renderer-check-') as temp:
        candidate = Path(temp) / 'OficioRedmexPdfRenderer.php'
        candidate.write_bytes(new)
        proc = subprocess.run(['php','-l',str(candidate)],
                              stdout=subprocess.PIPE, stderr=subprocess.PIPE)
        if proc.returncode:
            raise RuntimeError('SINTAXIS: ' + proc.stderr.decode('utf-8', 'replace')[:400])
    print(('YA INSTALADO: ' if old == new else 'COMPATIBLE: ') + PATH)
    print('INTEGRIDAD OK: renderer de GitHub y sintaxis PHP')
    print('CHECK OK: un archivo PHP; sin modificaciones de producción ni SQL')
    return file, old, new, stat.S_IMODE(file.stat().st_mode)


def publish(site, backup, source):
    ensure_backup_outside(site, backup)
    dest, old, new, mode = preflight(site, source)
    if old == new:
        print('YA INSTALADO: no se modificaron archivos')
        return
    backup.mkdir(mode=0o700, parents=True, exist_ok=True)
    os.chmod(str(backup), 0o700)
    lock = backup / '.oficios-renderer.lock'
    try:
        fd = os.open(str(lock), os.O_CREAT|os.O_EXCL|os.O_WRONLY, 0o600)
        os.close(fd)
    except FileExistsError:
        raise RuntimeError('Publicación en curso: bloqueo activo')
    try:
        _, old2, new2, mode2 = preflight(site, source)
        if old2 != old or new2 != new or mode2 != mode:
            raise RuntimeError('CONFLICTO: el renderer cambió durante la revisión')
        folder = Path(tempfile.mkdtemp(
            prefix=datetime.datetime.utcnow().strftime('%Y%m%d-%H%M%S-'),
            dir=str(backup)))
        os.chmod(str(folder), 0o700)
        original = folder / 'OficioRedmexPdfRenderer.php'
        original.write_bytes(old)
        (folder / 'respaldo.json').write_text(
            json.dumps({'site':str(site),'path':PATH,'before':sha256(old),
                        'after':sha256(new),'mode':mode}, indent=2),
            encoding='utf-8')
        if dest.read_bytes() != old:
            raise RuntimeError('CONFLICTO: modificación concurrente antes de publicar')
        atomic(dest, new, mode)
        if dest.read_bytes() != new:
            if dest.read_bytes() == new:
                atomic(dest, old, mode)
            raise RuntimeError('ERROR: instalación no verificada')
        print('APLICADO: renderer compatible con metadatos Word final')
        print('RESPALDO: ' + str(folder))
        print('Solo un PHP; sin SQL, plantilla DOCX, base de datos, folios ni otros módulos')
    finally:
        if lock.is_file():
            lock.unlink()


def restore(site, backup, folder_name):
    ensure_backup_outside(site, backup)
    folder = Path(folder_name).resolve()
    if backup not in folder.parents:
        raise RuntimeError('El respaldo no pertenece a la carpeta privada')
    manifest = json.loads((folder/'respaldo.json').read_text(encoding='utf-8'))
    if manifest.get('site') != str(site) or manifest.get('path') != PATH:
        raise RuntimeError('Manifest del respaldo no corresponde a este CRM')
    dest = validate_path(site, PATH)
    original = (folder / 'OficioRedmexPdfRenderer.php')
    if original.is_symlink() or not original.is_file():
        raise RuntimeError('El respaldo original no está disponible')
    data = original.read_bytes()
    if sha256(data) != manifest['before'] or sha256(dest.read_bytes()) != manifest['after']:
        raise RuntimeError('CONFLICTO: renderer modificado tras la publicación; no restaurar a ciegas')
    atomic(dest, data, int(manifest['mode']))
    print('RESTAURADO: únicamente el renderer REDMEX, sin SQL')


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--site',required=True)
    parser.add_argument('--source',required=True)
    parser.add_argument('--backup-root',required=True)
    mode = parser.add_mutually_exclusive_group(required=True)
    mode.add_argument('--check',action='store_true')
    mode.add_argument('--apply',action='store_true')
    mode.add_argument('--restore')
    args = parser.parse_args()
    site = Path(args.site).resolve()
    source = Path(args.source).resolve()
    backup = Path(args.backup_root).resolve()
    if args.check:
        preflight(site,source)
    elif args.apply:
        publish(site,backup,source)
    else:
        restore(site,backup,args.restore)


if __name__ == '__main__':
    try:
        main()
    except Exception as error:
        print('ERROR: ' + str(error), file=sys.stderr)
        sys.exit(1)
