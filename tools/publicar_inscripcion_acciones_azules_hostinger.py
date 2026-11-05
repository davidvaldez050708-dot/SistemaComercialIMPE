#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Publicar sólo color de botones en Inscripción. --check, --apply, --restore."""
import argparse
import datetime
import hashlib
import json
import os
from pathlib import Path
import stat
import sys
import tempfile

FILE = 'public/css/formularios.css'
BASE_BLOB = '6801ffb25f04dae03265e07eeeccf38a6932366e'
TARGET_BLOB = 'be0bb97138b9f9404f314cc537ef26ba39ea2ff8'
MARKER = b'ACCIONES AZULES Y ACENTOS VERDES'
CATALOG = 'INSCRIPCIÓN · PASO 1: CATÁLOGO POR PROGRAMA'.encode('utf-8')
BLUE = b'background: #223a84;'
GREEN_ICON = b'.formularios-inscripcion-programa-icon'
GREEN_COLOR = b'color: #09836b;'


def blob(data):
    return hashlib.sha1(('blob %d\0' % len(data)).encode('ascii') + data).hexdigest()


def sha(data):
    return hashlib.sha256(data).hexdigest()


def path_in(site):
    result = site / FILE
    if result.is_symlink() or not result.is_file() or site not in result.resolve().parents:
        raise RuntimeError('No se encuentra el CSS local esperado')
    return result


def backup_private(site, backup):
    if backup == site or site in backup.parents or backup in site.parents:
        raise RuntimeError('El respaldo debe estar fuera de public_html')


def inspect(site, basefile, targetfile):
    if not (site / 'index.php').is_file():
        raise RuntimeError('No se encontró la raíz del CRM')
    old = basefile.read_bytes()
    new = targetfile.read_bytes()
    if blob(old) != BASE_BLOB or blob(new) != TARGET_BLOB:
        raise RuntimeError('INTEGRIDAD GitHub incorrecta')
    prefix = old.rstrip(b'\r\n')
    if not new.startswith(prefix):
        raise RuntimeError('CONFLICTO: el destino no conserva el CSS original')
    addition = new[len(prefix):]
    if (addition.count(MARKER) != 1 or addition.count(BLUE) != 1 or
            b'.formularios-inscripcion-theme .btn-system-save' not in addition):
        raise RuntimeError('INTEGRIDAD: override CSS inesperado')
    if old.count(CATALOG) != 1 or old.count(GREEN_ICON) != 1:
        raise RuntimeError('INTEGRIDAD: catálogo anterior no reconocido')
    live = path_in(site)
    original = live.read_bytes()
    current = original.replace(b'\r\n', b'\n')
    if current.count(CATALOG) != 1 or current.count(GREEN_ICON) != 1 or \
            current.count(GREEN_COLOR) < 1:
        raise RuntimeError('CONFLICTO: el panel o sus iconos verdes fueron personalizados')
    if current.count(MARKER) > 0:
        if current.count(MARKER) == 1 and addition.strip() in current:
            print('YA INSTALADO: únicamente estilos de Inscripción')
            patched = original
        else:
            raise RuntimeError('CONFLICTO: ya hay una versión distinta de los colores')
    else:
        patch = addition.replace(b'\r\n', b'\n')
        normalized = current.rstrip(b'\n') + b'\n' + patch
        patched = normalized.replace(b'\n', b'\r\n') if b'\r\n' in original else normalized
        print('COMPATIBLE: CSS local y acentos verdes intactos')
    print('CHECK OK: sólo botones azules de Inscripción; Registro sin cambios')
    print('Sin SQL, datos, HTML, PHP, JS ni enlaces públicos modificados')
    return live, original, patched, stat.S_IMODE(live.stat().st_mode)


def atomic(path, content, mode):
    fd, temp = tempfile.mkstemp(prefix='.inscripcion-color-', dir=str(path.parent))
    try:
        with os.fdopen(fd, 'wb') as output:
            output.write(content)
            output.flush()
            os.fsync(output.fileno())
        os.chmod(temp, mode)
        os.replace(temp, str(path))
    finally:
        if os.path.exists(temp):
            os.unlink(temp)


def apply(site, backup, basefile, targetfile):
    backup_private(site, backup)
    path, original, patched, mode = inspect(site, basefile, targetfile)
    if original == patched:
        print('YA INSTALADO: sin modificaciones')
        return
    backup.mkdir(parents=True, mode=0o700, exist_ok=True)
    os.chmod(str(backup), 0o700)
    folder = Path(tempfile.mkdtemp(
        prefix=datetime.datetime.utcnow().strftime('%Y%m%d-%H%M%S-'),
        dir=str(backup)))
    os.chmod(str(folder), 0o700)
    original_path = folder / 'formularios.css'
    original_path.write_bytes(original)
    os.chmod(str(original_path), 0o600)
    manifest = {'site': str(site), 'file': FILE,
                'before': sha(original), 'after': sha(patched), 'mode': mode}
    meta = folder / 'respaldo.json'
    meta.write_text(json.dumps(manifest,indent=2),encoding='utf-8')
    os.chmod(str(meta), 0o600)
    if path.read_bytes() != original:
        raise RuntimeError('CONFLICTO: modificación simultánea del archivo local')
    atomic(path, patched, mode)
    if path.read_bytes() != patched:
        raise RuntimeError('No se pudo comprobar la publicación; revisar respaldo')
    print('APLICADO: botones azules, iconos verdes conservados')
    print('RESPALDO: ' + str(folder))


def restore(site, backup, folder_name):
    backup_private(site, backup)
    folder = Path(folder_name).resolve()
    if backup not in folder.parents:
        raise RuntimeError('Respaldo fuera de carpeta privada')
    manifest = json.loads((folder / 'respaldo.json').read_text(encoding='utf-8'))
    if manifest.get('site') != str(site) or manifest.get('file') != FILE:
        raise RuntimeError('Respaldo de otra instalación')
    path = path_in(site)
    original_path = folder / 'formularios.css'
    if not original_path.is_file() or original_path.is_symlink():
        raise RuntimeError('No se encontró el CSS de respaldo')
    old = original_path.read_bytes()
    if sha(old) != manifest['before'] or sha(path.read_bytes()) != manifest['after']:
        raise RuntimeError('CONFLICTO: CSS modificado después del APPLY')
    atomic(path, old, int(manifest['mode']))
    print('RESTAURADO: sólo formularios.css')


def main():
    p=argparse.ArgumentParser(description=__doc__)
    p.add_argument('--site',required=True)
    p.add_argument('--base',required=True)
    p.add_argument('--target',required=True)
    p.add_argument('--backup-root',required=True)
    action=p.add_mutually_exclusive_group(required=True)
    action.add_argument('--check',action='store_true')
    action.add_argument('--apply',action='store_true')
    action.add_argument('--restore')
    args=p.parse_args()
    site=Path(args.site).resolve()
    backup=Path(args.backup_root).resolve()
    if args.check:
        inspect(site,Path(args.base).resolve(),Path(args.target).resolve())
    elif args.apply:
        apply(site,backup,Path(args.base).resolve(),Path(args.target).resolve())
    else:
        restore(site,backup,args.restore)


if __name__ == '__main__':
    try:
        main()
    except Exception as exc:
        print('ERROR: '+str(exc),file=sys.stderr)
        sys.exit(1)
