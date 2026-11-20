#!/usr/bin/env python3
"""Logo de Fundación en formularios existentes: sustitución puntual con respaldo. Sin SQL."""
import argparse, contextlib, datetime, hashlib, json, os
from pathlib import Path
import shutil, subprocess, sys, tempfile

PATHS = [
    'app/views/formularios/publico_registro.php',
    'app/views/formularios/publico_inscripcion.php',
    'app/views/formularios/publico_inscripcion_completa.php',
    'app/views/formularios/inscripcion_ejecutivas_previa.php',
    'public/img/brand/fundacion-red-educativa-mexico-blanco.png',
]
BASES = {'app/views/formularios/publico_registro.php': {'7c9071d4cacb654c5660728cadfad5d5e1453217145202a909ae509318f04a72',
                                                'b8811c2b5987a2275827d5be86d4c51d0797a964ba0bfea9b9ede840d5e6eda1'},
 'app/views/formularios/publico_inscripcion.php': {'60a2b9174a166f2484529f66d596712fa13cccd9ed94d6a327aaa61902bd4304',
                                                   '79ef5ac4bd78433472f28dc8c13a9de5f6ac915fb00386b762946a17cfaa5119'},
 'app/views/formularios/publico_inscripcion_completa.php': {'43ddf33132efe5d1b982144d1d9e113a0eac9316a8607a864ca4b88b72a1120b',
                                                            'f53a7374d8e80a6c8752abffbcf7a43f4cf7c5d47e523542ad06a91bf963fe5d'},
 'app/views/formularios/inscripcion_ejecutivas_previa.php': {'04fec7052b071c945579e9fa7caa7c42ec06902e81b92e978d1dc22ff7335b9d'}}
LOGO_SHA = '58948cab5cb4cc78de2f6e4ed038e35790492d783b3aaf5c0dbcfdf43b83dc6f'
OLD_SRC = b'public/img/brand/Logotipos-blanco.png'
NEW_SRC = b'public/img/brand/fundacion-red-educativa-mexico-blanco.png'
OLD_ALT = 'alt="Fundación Red Educativa México y Universidad MPE"'.encode('utf-8')
NEW_ALT = 'alt="Fundación Red Educativa México"'.encode('utf-8')

def digest(data): return None if data is None else hashlib.sha256(data).hexdigest()
def read(path): return path.read_bytes() if path.is_file() else None

def mode_matches(path, mode):
    # Windows no representa los permisos POSIX; Hostinger sí los exige al servir JS.
    return os.name != 'posix' or (path.is_file() and path.stat().st_mode & 0o777 == mode)

def needs_change(row):
    return row['before'] != row['after'] or not mode_matches(row['dest'], row['after_mode'])
def safe(root, name):
    path = root/name
    if Path(name).is_absolute() or '..' in Path(name).parts or '\\' in name or path.is_symlink() or root not in path.resolve().parents:
        raise RuntimeError('Ruta fuera del alcance: '+name)
    return path

def lint(rows):
    if not any(row['name'].endswith('.php') for row in rows): return
    php = shutil.which('php')
    if not php: raise RuntimeError('PHP CLI no disponible.')
    runtime = subprocess.run([php, '-r', "exit(PHP_VERSION_ID >= 80100 && function_exists('mb_strtolower') && class_exists('finfo') && class_exists('mysqli') ? 0 : 1);"], stdout=subprocess.PIPE, stderr=subprocess.PIPE)
    if runtime.returncode: raise RuntimeError('Se requiere PHP 8.1 o posterior con mbstring, fileinfo y mysqli.')
    for row in rows:
        if not row['name'].endswith('.php'): continue
        with tempfile.TemporaryDirectory(prefix='impe-inscripcion-completa-lint-') as directory:
            path = Path(directory)/'archivo.php'
            path.write_bytes(row['after'])
            proc = subprocess.run([php, '-l', str(path)], stdout=subprocess.PIPE, stderr=subprocess.PIPE)
            if proc.returncode: raise RuntimeError('Sintaxis PHP inválida: '+row['name'])

def prepare(site, package=None):
    logo = (Path(__file__).resolve().parent.parent / PATHS[-1]).read_bytes()
    if digest(logo) != LOGO_SHA:
        raise RuntimeError('El logotipo no coincide con la imagen adjunta revisada.')
    rows = []
    for name in PATHS:
        dest = safe(site, name)
        before = read(dest)
        if name.endswith('.php'):
            if before is None:
                raise RuntimeError('Vista ausente: '+name)
            reversed_logo = before.replace(NEW_SRC, OLD_SRC).replace(NEW_ALT, OLD_ALT)
            if digest(before) not in BASES[name] and digest(reversed_logo) not in BASES[name]:
                raise RuntimeError('Dependencia protegida cambió: '+name+'. Sitio intacto.')
            if before.count(NEW_SRC) == 1 and before.count(NEW_ALT) == 1 and OLD_SRC not in before and OLD_ALT not in before:
                after = before
            elif before.count(OLD_SRC) == 1 and before.count(OLD_ALT) == 1 and NEW_SRC not in before:
                after = before.replace(OLD_SRC, NEW_SRC).replace(OLD_ALT, NEW_ALT)
            else:
                raise RuntimeError('Encabezado diferente al esperado: '+name+'. Sitio intacto.')
        else:
            if before is not None and digest(before) != LOGO_SHA:
                raise RuntimeError('El destino del logo contiene otra imagen; sitio intacto.')
            after = logo
        mode = dest.stat().st_mode & 0o777 if before is not None else 0o644
        rows.append({'name': name, 'dest': dest, 'before': before, 'after': after,
                     'mode': mode, 'after_mode': 0o644 if name.startswith('public/') else mode})
        print(('COMPATIBLE: ' if needs_change(rows[-1]) else 'YA INSTALADO: ')+name)
    lint(rows)
    return rows

def atomic(dest, data, mode):
    missing = []
    parent = dest.parent
    while not parent.exists():
        missing.append(parent); parent = parent.parent
    dest.parent.mkdir(parents=True, exist_ok=True)
    for parent in reversed(missing): os.chmod(str(parent), 0o755)
    fd, name = tempfile.mkstemp(prefix='.impe-inscripcion-completa-', dir=dest.parent)
    try:
        with os.fdopen(fd, 'wb') as stream:
            stream.write(data); stream.flush(); os.fsync(stream.fileno())
        os.chmod(name, mode)
        os.replace(name, dest)
    finally:
        if os.path.exists(name): os.unlink(name)

@contextlib.contextmanager
def lock(root):
    root.mkdir(parents=True, exist_ok=True)
    name = root/'.publicacion-formularios-logo.lock'
    fd = os.open(name, os.O_WRONLY|os.O_CREAT|os.O_EXCL, 0o600)
    try:
        os.write(fd, str(os.getpid()).encode()); os.close(fd)
        yield
    finally:
        name.unlink()

def apply(site, root, package):
    prepare(site, package)
    with lock(root):
        rows = prepare(site, package)
        changed = sorted([r for r in rows if needs_change(r)], key=lambda r: not r['name'].startswith('public/'))
        if not changed: print('YA INSTALADO: sin cambios.'); return
        backup = Path(tempfile.mkdtemp(prefix=datetime.datetime.now(datetime.timezone.utc).strftime('%Y%m%d-%H%M%S-'), dir=root))
        os.chmod(backup, 0o700)
        meta = {'site': str(site), 'files': []}
        for r in changed:
            if r['before'] is not None:
                dest = safe(backup, 'original/'+r['name']); dest.parent.mkdir(parents=True, exist_ok=True)
                dest.write_bytes(r['before'])
            meta['files'].append({'name': r['name'], 'before': digest(r['before']), 'after': digest(r['after']),
                                  'mode': r['mode'], 'after_mode': r['after_mode']})
        (backup/'respaldo.json').write_text(json.dumps(meta, indent=2), encoding='utf-8')
        installed = []
        try:
            for r in changed:
                if read(r['dest']) != r['before'] or (r['before'] is not None and not mode_matches(r['dest'], r['mode'])):
                    raise RuntimeError('Archivo cambiado durante la instalación: '+r['name'])
            for r in changed:
                if read(r['dest']) != r['before'] or (r['before'] is not None and not mode_matches(r['dest'], r['mode'])):
                    raise RuntimeError('Archivo cambiado durante la instalación: '+r['name'])
                atomic(r['dest'], r['after'], r['after_mode']); installed.append(r)
                if read(r['dest']) != r['after'] or not mode_matches(r['dest'], r['after_mode']):
                    raise RuntimeError('Verificación posterior fallida: '+r['name'])
        except BaseException:
            for r in reversed(installed):
                if read(r['dest']) != r['after'] or not mode_matches(r['dest'], r['after_mode']):
                    print('REVISAR RESTAURACIÓN: archivo editado por otro proceso: '+r['name'], file=sys.stderr)
                    continue
                if r['before'] is None: r['dest'].unlink()
                else: atomic(r['dest'], r['before'], r['mode'])
            raise
        print('APLICADO: Logo de Fundación actualizado en los cuatro encabezados. Sin SQL ni cambios de datos. RESPALDO: '+str(backup))

def restore(site, root, directory):
    backup = Path(directory).resolve()
    if backup == site or site in backup.parents or root not in backup.parents:
        raise RuntimeError('Respaldo fuera del directorio privado indicado.')
    with lock(root):
        data = json.loads((backup/'respaldo.json').read_text(encoding='utf-8'))
        if data.get('site') != str(site) or not data.get('files'): raise RuntimeError('Respaldo ajeno.')
        plan = []
        for row in data['files']:
            if row['name'] not in PATHS: raise RuntimeError('Archivo fuera del paquete.')
            dest = safe(site, row['name'])
            if digest(read(dest)) != row['after']: raise RuntimeError('Versión modificada después de instalar: '+row['name'])
            if not mode_matches(dest, row.get('after_mode', row['mode'])):
                raise RuntimeError('Permisos modificados después de instalar: '+row['name'])
            original = read(safe(backup, 'original/'+row['name'])) if row['before'] is not None else None
            if digest(original) != row['before']: raise RuntimeError('Respaldo dañado.')
            plan.append((dest, original, row['mode']))
        for dest, original, mode in reversed(plan):
            if original is None: dest.unlink()
            else: atomic(dest, original, mode)
        print('RESTAURADO: versión previa. Sin SQL.')

def main():
    if sys.version_info < (3, 6): raise RuntimeError('Se requiere Python 3.6 o posterior.')
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--site', required=True)
    parser.add_argument('--backup-root', required=True)
    actions = parser.add_mutually_exclusive_group(required=True)
    actions.add_argument('--check', action='store_true')
    actions.add_argument('--apply', action='store_true')
    actions.add_argument('--restore')
    args = parser.parse_args()
    site, root = Path(args.site).resolve(), Path(args.backup_root).resolve()
    if not (site/'index.php').is_file(): raise RuntimeError('El sitio no tiene index.php.')
    if root == site or site in root.parents: raise RuntimeError('El respaldo debe estar fuera del sitio.')
    package = None
    if args.restore: restore(site, root, args.restore)
    elif args.apply: apply(site, root, package)
    else:
        prepare(site, package)
        print('CHECK OK: sólo compatibilidad y sintaxis; sin escrituras ni consultas SQL.')

if __name__ == '__main__':
    try: main()
    except Exception as error:
        print('ERROR: '+str(error), file=sys.stderr)
        sys.exit(1)
