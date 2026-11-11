#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Publicador opt-in del diseño compacto de Gestión comercial.

Sólo: app/views/embudo_comercial/index.php y nuevo CSS aislado.
No escribe SQL, modifica el shell ni altera JavaScript o telefonía.
Python 3.6+. --check es estrictamente sólo lectura.
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

VIEW = 'app/views/embudo_comercial/index.php'
CSS = 'public/css/embudo_comercial_compacto_v1.css'
OLD_VIEW = '8c12c1af24c73badcb68ff372ea04e1ba0622c7c'
NEW_VIEW = '825b4c6af25450d4be21b47f8666955ada847eed'
NEW_CSS = 'c0ea09e7b35ee8c54d61f9386902bb8bcbac2ee4'
CONFIRM = 'PUBLICAR_SOLO_DISENO_EMBUDO_COMPACTO_V1'


class Blocked(Exception):
    pass


def blob(data):
    return hashlib.sha1(('blob %d\0' % len(data)).encode('ascii') + data).hexdigest()


def safe_file(root, relative, expected_exists):
    path = root / relative
    if not str(path.resolve(strict=False)).startswith(str(root) + os.sep):
        raise Blocked('Ruta fuera del sitio: ' + relative)
    if path.parent.is_symlink() or path.is_symlink():
        raise Blocked('Ruta enlazada no autorizada: ' + relative)
    if expected_exists:
        if not path.is_file() or not stat.S_ISREG(path.lstat().st_mode):
            raise Blocked('Archivo de producción no disponible: ' + relative)
    elif path.exists():
        if not path.is_file() or not stat.S_ISREG(path.lstat().st_mode):
            raise Blocked('Destino ocupado: ' + relative)
    return path


def root_site(site):
    root = Path(site).expanduser().resolve(strict=True)
    if not root.is_dir() or root.is_symlink():
        raise Blocked('Directorio del sitio no válido')
    return root


def input_files(source):
    base = Path(source).expanduser().resolve(strict=True)
    if not base.is_dir():
        raise Blocked('Fuentes inexistentes')
    result = {}
    for name, expected in ((VIEW, NEW_VIEW), (CSS, NEW_CSS)):
        path = safe_file(base, name, True)
        raw = path.read_bytes()
        if blob(raw) != expected:
            raise Blocked('Fuente no coincide con la versión revisada: ' + name)
        result[name] = raw
        print('FUENTE VERIFICADA:', name, expected)
    return base, result


def inspect(site, src):
    root = root_site(site)
    source, data = input_files(src)
    if source == root or root in source.parents:
        raise Blocked('No se permite tomar fuentes dentro de producción')
    view = safe_file(root, VIEW, True)
    current_view = blob(view.read_bytes())
    css = safe_file(root, CSS, False)
    current_css = blob(css.read_bytes()) if css.exists() else None
    old = current_view == OLD_VIEW and current_css is None
    done = current_view == NEW_VIEW and current_css == NEW_CSS
    if not old and not done:
        raise Blocked('Hostinger cambió o tiene una publicación parcial; '
                      'vista=%s; css=%s' % (current_view, current_css or 'AUSENTE'))
    if old:
        print('COMPATIBLE:', VIEW, OLD_VIEW)
        print('COMPATIBLE: CSS nuevo ausente, se creará sin reemplazar estilos.')
    else:
        print('YA PUBLICADO:', VIEW, NEW_VIEW)
        print('YA PUBLICADO:', CSS, NEW_CSS)
    print('PROTEGIDO: CSS original, JavaScript, servicios PHP, shell WebRTC y base de datos.')
    return root, data, old


def atomic_write(path, raw, mode):
    fd, temp = tempfile.mkstemp(prefix='.impe-embudo-ui-', dir=str(path.parent))
    try:
        with os.fdopen(fd, 'wb') as h:
            h.write(raw)
            h.flush()
            os.fsync(h.fileno())
        os.chmod(temp, mode)
        os.replace(temp, str(path))
    finally:
        if os.path.exists(temp):
            os.unlink(temp)


def atomic_create(path, raw):
    flags = os.O_WRONLY | os.O_CREAT | os.O_EXCL
    fd = os.open(str(path), flags, 0o644)
    try:
        with os.fdopen(fd, 'wb') as h:
            h.write(raw)
            h.flush()
            os.fsync(h.fileno())
    except Exception:
        if path.is_file() and blob(path.read_bytes()) == blob(raw):
            path.unlink()
        raise


def backup_dir(root):
    root.mkdir(mode=0o700, parents=True, exist_ok=True)
    if root.is_symlink() or not root.is_dir():
        raise Blocked('Carpeta de respaldo no confiable')
    os.chmod(str(root), 0o700)
    path = root / (datetime.datetime.utcnow().strftime('%Y%m%d-%H%M%S-') +
                   secrets.token_hex(4))
    path.mkdir(mode=0o700)
    return path


def publish(site, src, root_backups, confirmation):
    if confirmation != CONFIRM:
        raise Blocked('Publicación requiere confirmación explícita')
    root, data, old = inspect(site, src)
    if not old:
        print('SIN CAMBIOS: diseño ya publicado')
        return
    view = safe_file(root, VIEW, True)
    css = safe_file(root, CSS, False)
    if css.exists():
        raise Blocked('CSS inesperado; no reemplazar')
    original = view.read_bytes()
    if blob(original) != OLD_VIEW:
        raise Blocked('Vista cambió tras el check')
    mode = stat.S_IMODE(view.lstat().st_mode)
    base = (Path(root_backups).expanduser().resolve(strict=False) if root_backups else
            Path.home() / 'impe-embudo-diseno-compacto-v1' / 'backups')
    if root in base.parents or base == root:
        raise Blocked('Respaldos deben guardarse fuera del sitio web')
    folder = backup_dir(base)
    backed = folder / 'index.php'
    backed.write_bytes(original)
    os.chmod(str(backed), 0o600)
    manifest = folder / 'respaldo.json'
    manifest.write_text(json.dumps({
        'site': str(root), 'view': VIEW, 'css': CSS,
        'view_old': OLD_VIEW, 'view_new': NEW_VIEW,
        'css_new': NEW_CSS, 'mode': mode
    }, indent=2), encoding='utf-8')
    os.chmod(str(manifest), 0o600)
    if blob(backed.read_bytes()) != OLD_VIEW:
        raise Blocked('Respaldo no verificable; sin publicación')
    if blob(view.read_bytes()) != OLD_VIEW or css.exists():
        raise Blocked('Producción cambió antes de publicar')
    # El CSS se crea antes de referenciarlo desde la vista.
    try:
        atomic_create(css, data[CSS])
        atomic_write(view, data[VIEW], mode)
        if blob(view.read_bytes()) != NEW_VIEW or blob(css.read_bytes()) != NEW_CSS:
            raise Blocked('Falló verificación posterior; se revierte')
    except Exception:
        if blob(view.read_bytes()) == NEW_VIEW:
            atomic_write(view, original, mode)
        if css.is_file() and blob(css.read_bytes()) == NEW_CSS:
            css.unlink()
        raise
    print('PUBLICADO:', CSS, NEW_CSS)
    print('PUBLICADO:', VIEW, NEW_VIEW)
    print('RESPALDO PRIVADO:', folder)
    print('POST-CHECK OK: solamente diseño visual del embudo')


def rollback(site, folder, confirmation):
    if confirmation != CONFIRM:
        raise Blocked('Reversión requiere confirmación explícita')
    root = root_site(site)
    backup = Path(folder).expanduser().resolve(strict=True)
    meta_path = backup / 'respaldo.json'
    view_backup = backup / 'index.php'
    if meta_path.is_symlink() or view_backup.is_symlink():
        raise Blocked('Respaldo enlazado no autorizado')
    meta = json.loads(meta_path.read_text(encoding='utf-8'))
    old = view_backup.read_bytes()
    if meta.get('site') != str(root) or meta.get('view') != VIEW or \
       meta.get('css') != CSS or blob(old) != OLD_VIEW or \
       meta.get('view_old') != OLD_VIEW or meta.get('view_new') != NEW_VIEW or \
       meta.get('css_new') != NEW_CSS:
        raise Blocked('Respaldo distinto del sitio o versión')
    view = safe_file(root, VIEW, True)
    css = safe_file(root, CSS, True)
    if blob(view.read_bytes()) != NEW_VIEW or blob(css.read_bytes()) != NEW_CSS:
        raise Blocked('Hay cambios posteriores; no se alteran personalizaciones')
    atomic_write(view, old, int(meta['mode']))
    css.unlink()
    if blob(view.read_bytes()) != OLD_VIEW or css.exists():
        raise Blocked('No fue posible verificar reversión')
    print('ROLLBACK OK: vista original y CSS adicional retirado; sin alterar datos')


def main():
    p = argparse.ArgumentParser(description=__doc__)
    p.add_argument('--site', required=True)
    p.add_argument('--source-dir')
    p.add_argument('--backup-root')
    p.add_argument('--backup-folder')
    p.add_argument('--confirm', default='')
    mode = p.add_mutually_exclusive_group(required=True)
    mode.add_argument('--check', action='store_true')
    mode.add_argument('--apply', action='store_true')
    mode.add_argument('--rollback', action='store_true')
    args = p.parse_args()
    try:
        if args.rollback:
            if not args.backup_folder:
                raise Blocked('Falta --backup-folder')
            rollback(args.site, args.backup_folder, args.confirm)
        else:
            if not args.source_dir:
                raise Blocked('Falta --source-dir')
            if args.check:
                inspect(args.site, args.source_dir)
                print('CHECK OK: sólo lectura; sin modificaciones')
            else:
                publish(args.site, args.source_dir, args.backup_root, args.confirm)
    except (Blocked, OSError, KeyError, ValueError) as e:
        print('BLOQUEADO:', e, file=sys.stderr)
        return 2
    return 0


if __name__ == '__main__':
    sys.exit(main())
