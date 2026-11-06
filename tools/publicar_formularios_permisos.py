#!/usr/bin/env python3
"""Permisos configurables de Formularios y errores uniformes. Publicación selectiva reversible; sin SQL ni cambios de datos."""
import argparse, base64, contextlib, datetime, gzip, hashlib, json, os
from pathlib import Path
import shutil, subprocess, sys, tempfile

MANIFEST_SHA256 = '5e0265380324c5192e49d4a5545e7737ecb7ac26fb7344aed5af552567226b1a'
PATHS = ['app/helpers/FormularioPermisos.php', 'app/models/RolModel.php', 'app/controllers/FormularioController.php', 'app/views/layout/sidebar.php', 'app/views/formularios/registro.php', 'app/views/formularios/inscripcion.php', 'app/views/formularios/inscripcion_completa.php', 'app/views/formularios/inscripcion_programas.php', 'public/css/formularios_permisos.css', 'public/css/formularios_inscripcion_completa.css', 'public/javascript/formularios_inscripcion_completa.js']
DEPENDENCIES = {'app/helpers/PermissionHelper.php': '1ef40e3df10307ec89d047c32a373c8244fd03e83828200d63af9d34680f5bc2', 'app/helpers/AdminRolePolicy.php': '433b66b7e2b9e764910a5acf5dc3223b065bda47299b81314db4ba8a7f3d7a86', 'app/helpers/SupervisionRolePolicy.php': 'f9542f3c578d953cfde72f807cf98bc770beffac7e606f8bf9f975d5324d001b', 'app/helpers/HorarioContacto.php': '0e03d42f9f508c0e1db666e88d5dbcc81978c55f0187a605b519e6cb63b12156', 'app/helpers/ProgramaEducativo.php': '21cf8e1a52be8245511bb048b1439f957768a5d4350f7ae8600e44b98063e455', 'app/models/FormularioRegistroModel.php': '5089e339748fa7cd40bc78b659ea21870a48ace6618dc987b2e52b7047791f12', 'app/services/RegistroEnlaceService.php': '7eb4d1c689ecfb35bc3f850dc90b437e81f7239b7abe6377b5fe6f5914b88c87'}

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

def payload(encoded, expected):
    data = gzip.decompress(base64.b64decode(encoded, validate=True))
    if len(data) > 2*1024*1024 or digest(data) != expected:
        raise RuntimeError('Contenido del paquete alterado.')
    return data

def manifest(path):
    raw = path.read_bytes()
    if digest(raw) != MANIFEST_SHA256: raise RuntimeError('Manifiesto diferente; sitio intacto.')
    data = json.loads(raw)
    if data.get('schema') != 1 or [r['path'] for r in data['files']] != PATHS:
        raise RuntimeError('Paquete ajeno.')
    if data.get('dependencies') != DEPENDENCIES: raise RuntimeError('Dependencias ajenas.')
    return data['files']

def lint(rows):
    php = shutil.which('php')
    if not php: raise RuntimeError('PHP CLI no disponible.')
    runtime = subprocess.run([php, '-r', "exit(PHP_VERSION_ID >= 80100 && function_exists('mb_strtolower') && class_exists('finfo') && class_exists('mysqli') ? 0 : 1);"], stdout=subprocess.PIPE, stderr=subprocess.PIPE)
    if runtime.returncode: raise RuntimeError('Se requiere PHP 8.1 o posterior con mbstring, fileinfo y mysqli.')
    for row in rows:
        if not row['name'].endswith('.php'): continue
        with tempfile.TemporaryDirectory(prefix='impe-formularios-permisos-lint-') as directory:
            path = Path(directory)/'archivo.php'
            path.write_bytes(row['after'])
            proc = subprocess.run([php, '-l', str(path)], stdout=subprocess.PIPE, stderr=subprocess.PIPE)
            if proc.returncode: raise RuntimeError('Sintaxis PHP inválida: '+row['name'])

def prepare(site, package):
    for name, expected in DEPENDENCIES.items():
        if digest(read(safe(site, name))) != expected:
            raise RuntimeError('DEPENDENCIA DIFERENTE: '+name+'. Sitio intacto.')
    rows = []
    for file in package:
        dest = safe(site, file['path'])
        before = read(dest)
        current = digest(before)
        variant = next((v for v in file['variants'] if current in [v['before'], v['after']]), None)
        if variant is None: raise RuntimeError('VERSIÓN DIFERENTE: '+file['path']+'. Sitio intacto.')
        after = payload(variant['target'], variant['after'])
        mode = dest.stat().st_mode & 0o777 if before is not None else 0o644
        row = {'name': file['path'], 'dest': dest, 'before': before, 'after': after,
               'mode': mode, 'after_mode': 0o644 if file['path'].startswith(('public/','ya-estas-inscrito/')) else mode}
        rows.append(row)
        print(('COMPATIBLE: ' if needs_change(row) else 'YA INSTALADO: ')+file['path'])
        if before is not None and not mode_matches(dest, row['after_mode']):
            print('PERMISOS: '+file['path']+' '+oct(mode)[2:]+' -> '+oct(row['after_mode'])[2:])
    lint(rows)
    return rows

def atomic(dest, data, mode):
    missing = []
    parent = dest.parent
    while not parent.exists():
        missing.append(parent); parent = parent.parent
    dest.parent.mkdir(parents=True, exist_ok=True)
    for parent in reversed(missing): os.chmod(str(parent), 0o755)
    fd, name = tempfile.mkstemp(prefix='.impe-formularios-permisos-', dir=dest.parent)
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
    name = root/'.publicacion-formularios-permisos.lock'
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
        changed = [r for r in rows if needs_change(r)]
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
        print('APLICADO: Formularios con permisos configurables y errores uniformes. Sin SQL. Abre Roles y permisos como Administrador para incorporar el nuevo catálogo. RESPALDO: '+str(backup))

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
    parser.add_argument('--manifest', default=str(Path(__file__).with_name('formularios_permisos_release.json')))
    actions = parser.add_mutually_exclusive_group(required=True)
    actions.add_argument('--check', action='store_true')
    actions.add_argument('--apply', action='store_true')
    actions.add_argument('--restore')
    args = parser.parse_args()
    site, root = Path(args.site).resolve(), Path(args.backup_root).resolve()
    if not (site/'index.php').is_file(): raise RuntimeError('El sitio no tiene index.php.')
    if root == site or site in root.parents: raise RuntimeError('El respaldo debe estar fuera del sitio.')
    package = manifest(Path(args.manifest))
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
