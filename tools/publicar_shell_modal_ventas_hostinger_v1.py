#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Parche opt-in para el shell personalizado de Hostinger.

Solo sustituye UNA expresión de validación del propietario del modal de Ventas.
Prohíbe aplicar si el blob de origen NO coincide con el auditado. No lee SQL,
no modifica PHP, SDK, grabaciones ni el teléfono de forma automática.
Compatible con Python 3.6.
"""
import argparse
import datetime
import hashlib
import json
import os
import secrets
import stat
import sys
import tempfile
from pathlib import Path

REL = 'public/javascript/telefonia_shell.js'
BEFORE = '2f17ebdf0c25a6a9a7cb3e2c9b105b754900c3e5'
OLD = b"if(resultFormOwner && !crm.contentDocument?.querySelector('#modalLlamadaEntranteAnalista.show')){"
NEW = b"if(resultFormOwner && !crm.contentDocument?.querySelector('#modalLlamadaEntranteAnalista.show,#modalResultadoVenta')){"
CONFIRM = 'PUBLICAR_SOLO_SHELL_MODAL_VENTAS_V1'


class Blocked(Exception):
    pass


def blob(data):
    header = ('blob %d\0' % len(data)).encode('ascii')
    return hashlib.sha1(header + data).hexdigest()


def patch(data):
    if data.count(OLD) != 1:
        raise Blocked('La condición original no es única o ha cambiado; no se publica.')
    if data.count(NEW) != 0:
        raise Blocked('Ya aparece la corrección; no se aplica dos veces.')
    revised = data.replace(OLD, NEW, 1)
    if revised.replace(NEW, OLD, 1) != data:
        raise Blocked('No se ha conservado exactamente el resto del archivo.')
    return revised


def target_for(site):
    base = Path(site).expanduser().resolve(strict=True)
    target = base / REL
    if target.is_symlink() or not target.is_file():
        raise Blocked('Shell ausente, enlazado o no regular.')
    if target.parent.is_symlink():
        raise Blocked('El directorio del JavaScript es un enlace simbólico.')
    if not str(target.resolve(strict=True)).startswith(str(base) + os.sep):
        raise Blocked('El shell está fuera del sitio indicado.')
    return target


def read_safe(target):
    mode = target.lstat().st_mode
    if not stat.S_ISREG(mode) or target.is_symlink():
        raise Blocked('El shell dejó de ser un archivo regular.')
    return target.read_bytes(), stat.S_IMODE(mode)


def atomic_write(target, content, mode):
    fd, name = tempfile.mkstemp(prefix='.impe-modal-shell-', dir=str(target.parent))
    try:
        with os.fdopen(fd, 'wb') as f:
            f.write(content)
            f.flush()
            os.fsync(f.fileno())
        os.chmod(name, mode)
        os.replace(name, str(target))
    finally:
        if os.path.exists(name):
            os.unlink(name)


def inspect(site):
    target = target_for(site)
    current, mode = read_safe(target)
    current_sha = blob(current)
    if current_sha == BEFORE:
        candidate = patch(current)
        print('COMPATIBLE: shell personalizado de Hostinger', current_sha)
        print('CAMBIO PERMITIDO: una sola condición de propiedad del modal.')
        print('SHA DESPUÉS (PREPARADO):', blob(candidate))
        print('CONSERVADO: todos los demás bytes del shell, incluido el WebRTC.')
        return target, current, candidate, mode, False
    if current.count(NEW) == 1 and current.replace(NEW, OLD, 1) != current:
        reconstructed = current.replace(NEW, OLD, 1)
        if blob(reconstructed) == BEFORE:
            print('YA PUBLICADO: parche exacto detectado; sin nuevas acciones.')
            return target, reconstructed, current, mode, True
    raise Blocked('SHA de Hostinger distinto: %s. No se toca producción.' % current_sha)


def get_backup_root():
    root = Path.home() / 'impe-embudo-cierre-api-v1' / 'backups-shell'
    root.mkdir(mode=0o700, parents=True, exist_ok=True)
    if root.is_symlink():
        raise Blocked('Directorio de respaldo no confiable.')
    os.chmod(str(root), 0o700)
    return root


def apply(site, confirmation):
    if confirmation != CONFIRM:
        raise Blocked('Falta confirmación explícita. No se ha publicado.')
    target, old, new, mode, already = inspect(site)
    if already:
        return
    root = get_backup_root()
    folder = root / (datetime.datetime.utcnow().strftime('%Y%m%d-%H%M%S-') + secrets.token_hex(4))
    folder.mkdir(mode=0o700)
    backup = folder / 'telefonia_shell.js'
    meta = folder / 'respaldo.json'
    backup.write_bytes(old)
    os.chmod(str(backup), 0o600)
    manifest = {'relative_path': REL, 'original_sha': BEFORE,
                'published_sha': blob(new), 'target': str(target), 'mode': mode}
    meta.write_text(json.dumps(manifest, indent=2), encoding='utf-8')
    os.chmod(str(meta), 0o600)
    if blob(backup.read_bytes()) != BEFORE:
        raise Blocked('Respaldo no coincide. Sin publicación.')
    now, _ = read_safe(target)
    if now != old:
        raise Blocked('El shell cambió desde la revisión. Sin publicación.')
    try:
        atomic_write(target, new, mode)
        actual, _ = read_safe(target)
        if actual != new:
            raise Blocked('Verificación posterior falló.')
    except Exception:
        actual, _ = read_safe(target)
        if actual == new:
            atomic_write(target, old, mode)
        raise
    print('PUBLICADO: exactamente una condición del shell; sin otros cambios.')
    print('RESPALDO PRIVADO:', str(folder))
    print('SHA PUBLICADO:', blob(new))


def rollback(site, backup_dir, confirmation):
    if confirmation != CONFIRM:
        raise Blocked('Falta confirmación explícita para revertir.')
    target = target_for(site)
    directory = Path(backup_dir).expanduser().resolve(strict=True)
    root = get_backup_root().resolve(strict=True)
    if directory.parent != root:
        raise Blocked('El respaldo no pertenece a la carpeta privada autorizada.')
    raw = directory / 'telefonia_shell.js'
    manifest = directory / 'respaldo.json'
    if raw.is_symlink() or manifest.is_symlink() or not raw.is_file() or not manifest.is_file():
        raise Blocked('Respaldo incompleto o enlazado.')
    info = json.loads(manifest.read_text(encoding='utf-8'))
    restored = raw.read_bytes()
    current, _ = read_safe(target)
    if info.get('target') != str(target) or info.get('relative_path') != REL or \
       blob(restored) != BEFORE or info.get('original_sha') != BEFORE:
        raise Blocked('Los datos del respaldo no corresponden al shell auditado.')
    if blob(current) != info.get('published_sha') or current.replace(NEW, OLD, 1) != restored:
        raise Blocked('Se detectó una personalización posterior. No se revierte.')
    atomic_write(target, restored, int(info['mode']))
    if target.read_bytes() != restored:
        raise Blocked('No se pudo verificar la restauración.')
    print('RESTAURADO: shell original exacto', BEFORE)


def main():
    p = argparse.ArgumentParser(description=__doc__)
    p.add_argument('--site', required=True)
    modes = p.add_mutually_exclusive_group(required=True)
    modes.add_argument('--check', action='store_true')
    modes.add_argument('--apply', action='store_true')
    modes.add_argument('--rollback', metavar='DIRECTORIO_RESPALDO')
    p.add_argument('--confirm', default='')
    args = p.parse_args()
    try:
        if args.check:
            inspect(args.site)
            print('CHECK OK: inspección solo lectura; sin publicar ni crear respaldos.')
        elif args.apply:
            apply(args.site, args.confirm)
        else:
            rollback(args.site, args.rollback, args.confirm)
    except (Blocked, OSError, ValueError, KeyError) as exc:
        print('BLOQUEADO:', exc, file=sys.stderr)
        return 2
    return 0


if __name__ == '__main__':
    sys.exit(main())
