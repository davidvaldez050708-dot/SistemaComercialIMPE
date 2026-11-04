#!/usr/bin/env python3
"""Publicación selectiva y reversible de DENUE. No ejecuta CRM, SQL ni llamadas."""
import argparse, base64, contextlib, datetime, gzip, hashlib, json, os
from pathlib import Path
import shutil, subprocess, sys, tempfile

MANIFEST_SHA256 = 'a2947327ac03e006760768677684a5b1cddf0d966e586b1e8e0f489d380c6f2f'
PATHS = ['config/certificates/inegi-ca-chain.pem', 'app/services/DenueTlsPolicy.php',
         'app/services/DenueCandidatosHttpClient.php', 'app/services/DenueService.php',
         'app/controllers/SeguimientoVinculacionController.php']

def digest(data): return None if data is None else hashlib.sha256(data).hexdigest()
def read(path): return path.read_bytes() if path.is_file() else None
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
    return data['files']

def lint(rows):
    php = shutil.which('php')
    if not php: raise RuntimeError('PHP CLI no disponible.')
    runtime = subprocess.run([php, '-r', "exit(PHP_VERSION_ID >= 80100 && function_exists('curl_multi_init') && function_exists('openssl_x509_parse') ? 0 : 1);"], stdout=subprocess.PIPE, stderr=subprocess.PIPE)
    if runtime.returncode: raise RuntimeError('Se requiere PHP 8.1 o posterior con cURL y OpenSSL.')
    for row in rows:
        if not row['name'].endswith('.php'): continue
        with tempfile.TemporaryDirectory(prefix='impe-denue-lint-') as directory:
            path = Path(directory)/'archivo.php'
            path.write_bytes(row['after'])
            proc = subprocess.run([php, '-l', str(path)], stdout=subprocess.PIPE, stderr=subprocess.PIPE)
            if proc.returncode: raise RuntimeError('Sintaxis PHP inválida: '+row['name'])

def prepare(site, package):
    rows = []
    for file in package:
        dest = safe(site, file['path'])
        before = read(dest)
        current = digest(before)
        variant = next((v for v in file['variants'] if current in [v['before'], v['after']]), None)
        if variant is None: raise RuntimeError('VERSIÓN DIFERENTE: '+file['path']+'. Sitio intacto.')
        after = payload(variant['target'], variant['after'])
        rows.append({'name': file['path'], 'dest': dest, 'before': before, 'after': after,
                     'mode': dest.stat().st_mode & 0o777 if before is not None else 0o644})
        print(('YA INSTALADO: ' if before == after else 'COMPATIBLE: ')+file['path'])
    lint(rows)
    return rows

def atomic(dest, data, mode):
    dest.parent.mkdir(parents=True, exist_ok=True)
    fd, name = tempfile.mkstemp(prefix='.impe-denue-', dir=dest.parent)
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
    name = root/'.publicacion-denue.lock'
    fd = os.open(name, os.O_WRONLY|os.O_CREAT|os.O_EXCL, 0o600)
    try:
        os.write(fd, str(os.getpid()).encode()); os.close(fd)
        yield
    finally:
        name.unlink()

def apply(site, root, package):
    with lock(root):
        rows = prepare(site, package)
        changed = [r for r in rows if r['before'] != r['after']]
        if not changed: print('YA INSTALADO: sin cambios.'); return
        backup = Path(tempfile.mkdtemp(prefix=datetime.datetime.now(datetime.timezone.utc).strftime('%Y%m%d-%H%M%S-'), dir=root))
        os.chmod(backup, 0o700)
        meta = {'site': str(site), 'files': []}
        for r in changed:
            if r['before'] is not None:
                dest = safe(backup, 'original/'+r['name']); dest.parent.mkdir(parents=True, exist_ok=True)
                dest.write_bytes(r['before'])
            meta['files'].append({'name': r['name'], 'before': digest(r['before']), 'after': digest(r['after']), 'mode': r['mode']})
        (backup/'respaldo.json').write_text(json.dumps(meta, indent=2), encoding='utf-8')
        installed = []
        try:
            for r in changed:
                if read(r['dest']) != r['before']: raise RuntimeError('Archivo cambiado durante la instalación: '+r['name'])
            for r in changed:
                if read(r['dest']) != r['before']: raise RuntimeError('Archivo cambiado durante la instalación: '+r['name'])
                atomic(r['dest'], r['after'], r['mode']); installed.append(r)
                if read(r['dest']) != r['after']: raise RuntimeError('Verificación posterior fallida: '+r['name'])
        except BaseException:
            for r in reversed(installed):
                if read(r['dest']) != r['after']:
                    print('REVISAR RESTAURACIÓN: archivo editado por otro proceso: '+r['name'], file=sys.stderr)
                    continue
                if r['before'] is None: r['dest'].unlink()
                else: atomic(r['dest'], r['before'], r['mode'])
            raise
        print('APLICADO: conexión segura y recuperación de candidatos DENUE. Sin SQL. RESPALDO: '+str(backup))

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
    parser.add_argument('--manifest', default=str(Path(__file__).with_name('denue_estabilidad_release.json')))
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
