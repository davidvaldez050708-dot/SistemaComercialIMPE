#!/usr/bin/env python3
"""Publicación CSS aditiva: conserva exactamente el CSS auditado de producción."""
import argparse
import hashlib
import json
import os
from pathlib import Path
import stat
import tempfile

CSS = 'public/css/formularios.css'
BASES = {
    '4fb13aab55b739839a1ed8ecaa53b5279ea0fff073c88efd98a0bef245df71be',
    'e952f1ba689c5b1b2b0757d56a6a9da94fca636e2f586b00931868a69399dec0',
}
VIEWS = {
    'app/views/formularios/index.php': 'da63d0e794fa57646e27bab70808c87c8d2ca2954e9e58c4430b41a52a2b4666',
    'app/views/formularios/inscripcion_programas.php': '7639dd4821a45de4d65d093511cf7c30111d947fde16fb7af10c7a1906bed120',
}
PAYLOAD_SHA = 'd0df2c8ef1957a3146bffc413c1c13d5f3f30266d53447fa3896d14e64ed872b'


def sha(data):
    return hashlib.sha256(data).hexdigest()


def target(site, relative):
    path = site / relative
    if path.is_symlink() or not path.is_file() or path.resolve() != path.absolute():
        raise RuntimeError('Ruta ausente o enlazada: ' + relative)
    return path


def atomic(path, data, mode):
    fd, name = tempfile.mkstemp(prefix='.formularios-', dir=str(path.parent))
    try:
        with os.fdopen(fd, 'wb') as out:
            out.write(data)
            out.flush()
            os.fsync(out.fileno())
        os.chmod(name, mode)
        os.replace(name, path)
    finally:
        if os.path.exists(name):
            os.unlink(name)


def outside(path, site):
    if path == site or site in path.parents:
        raise RuntimeError('El respaldo debe quedar fuera del sitio público.')


def run(args):
    site = Path(args.site).resolve(strict=True)
    css = target(site, CSS)
    payload = Path(__file__).with_name('formularios_navegacion_compacta.css').read_bytes()
    if sha(payload) != PAYLOAD_SHA:
        raise RuntimeError('El bloque CSS no coincide con la entrega revisada.')
    for relative, expected in VIEWS.items():
        if sha(target(site, relative).read_bytes()) != expected:
            raise RuntimeError('Dependencia protegida cambió: ' + relative)
    current = css.read_bytes()
    if args.restore:
        backup = Path(args.restore).resolve(strict=True)
        outside(backup, site)
        manifest = json.loads((backup / 'manifest.json').read_text(encoding='utf-8'))
        original = (backup / 'formularios.css').read_bytes()
        if manifest['site'] != str(site) or sha(original) not in BASES:
            raise RuntimeError('El respaldo no corresponde al sitio y versión auditados.')
        if sha(original) != manifest['before'] or sha(original + payload) != manifest['after']:
            raise RuntimeError('Integridad de respaldo inválida.')
        if current == original:
            print('RESTORE_OK: ya estaba restaurado.')
            return
        if current != original + payload:
            raise RuntimeError('El CSS cambió después de publicar; no se sobrescribe.')
        atomic(css, original, stat.S_IMODE(css.stat().st_mode))
        if css.read_bytes() != original:
            raise RuntimeError('No se pudo verificar la restauración.')
        print('RESTORE_OK: CSS original restaurado.')
        return
    installed = current.endswith(payload) and sha(current[:-len(payload)]) in BASES
    if not installed and sha(current) not in BASES:
        raise RuntimeError('Dependencia protegida cambió: ' + CSS)
    if args.check or installed:
        print('CHECK_OK: ' + ('diseño compacto instalado.' if installed else 'base compatible; listo para aplicar.'))
        return
    if not args.backup_root:
        raise RuntimeError('--apply requiere --backup-root privado.')
    root = Path(args.backup_root).resolve()
    outside(root, site)
    root.mkdir(parents=True, exist_ok=True, mode=0o700)
    lock = root / '.formularios.lock'
    fd = os.open(str(lock), os.O_CREAT | os.O_EXCL | os.O_WRONLY, 0o600)
    os.close(fd)
    try:
        if css.read_bytes() != current:
            raise RuntimeError('El CSS cambió durante la revisión.')
        backup = Path(tempfile.mkdtemp(prefix='formularios-', dir=str(root)))
        os.chmod(backup, 0o700)
        atomic(backup / 'formularios.css', current, 0o600)
        candidate = current + payload
        manifest = {'site': str(site), 'before': sha(current), 'after': sha(candidate)}
        atomic(backup / 'manifest.json', json.dumps(manifest, indent=2).encode('utf-8'), 0o600)
        if (backup / 'formularios.css').read_bytes() != current:
            raise RuntimeError('No se pudo verificar el respaldo.')
        if css.read_bytes() != current:
            raise RuntimeError('El CSS cambió antes de publicar.')
        atomic(css, candidate, stat.S_IMODE(css.stat().st_mode))
        if css.read_bytes() != candidate:
            raise RuntimeError('El archivo publicado no coincide con la entrega.')
        print('APPLY_OK: estilos compactos agregados; CSS previo conservado íntegramente.')
        print('RESPALDO: ' + str(backup))
    finally:
        lock.unlink()


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--site', required=True)
    parser.add_argument('--backup-root')
    action = parser.add_mutually_exclusive_group(required=True)
    action.add_argument('--check', action='store_true')
    action.add_argument('--apply', action='store_true')
    action.add_argument('--restore', metavar='RESPALDO')
    try:
        run(parser.parse_args())
    except (OSError, ValueError, KeyError, RuntimeError) as error:
        parser.exit(1, 'BLOQUEADO: ' + str(error) + '\n')


if __name__ == '__main__':
    main()
