#!/usr/bin/env python3
"""Publicador selectivo de tres archivos DENUE. No ejecuta CRM ni SQL."""
import argparse, contextlib, hashlib, json, os, shutil, stat, subprocess, sys, tempfile
from pathlib import Path

# Orden de publicación: dependencia nueva, cliente, servicio.
FILES = [
    ('app/services/DenueCircuitBreaker.php', None, 'a62f4fd318894a747cb3f25e4e3c1513c5a7cf19'),
    ('app/services/DenueCandidatosHttpClient.php', 'af8ca6d58fab538a1818961b3d43e670e2e0da7d', '8a32bb908aaf9d9d76edd2c480ee8a9fac5043ef'),
    ('app/services/DenueService.php', '42c41c63627cd9b7019a67a3b0c06a617d35d88c', '06d0180b26d88d3475dc5bb8ca1f70e605c14f35'),
]
DEPENDENCIES = [
    ('app/services/DenueCandidatosConsultaService.php', 'd56b37fa8b9849ebb1cd6091aefc1b00b0f7ba90'),
    ('app/services/DenueTlsPolicy.php', '8d6dafb001cf56262c7de471189847ab85d735ee'),
    ('app/controllers/SeguimientoVinculacionController.php', 'e41e4760352fa74d76a52605d83a8fc4ae37b2a1'),
    ('app/views/seguimiento_vinculacion/estado.php', '492045e1f549c38c246da213025ba3f7873bdeae'),
]
def digest(data):
    if data is None: return None
    return hashlib.sha1(('blob %d\0' % len(data)).encode('ascii') + data).hexdigest()
def safe(root, name):
    p = root / name
    if Path(name).is_absolute() or '..' in Path(name).parts or p.is_symlink() or root not in p.resolve().parents:
        raise RuntimeError('Ruta no permitida: ' + name)
    if p.exists() and not p.is_file(): raise RuntimeError('No es archivo: ' + name)
    return p
def read(p):
    return p.read_bytes() if p.is_file() else None
def mode(p):
    return stat.S_IMODE(p.stat().st_mode)
def atomic(p, data, permissions):
    fd, tmp = tempfile.mkstemp(prefix='.denue-circuito-', dir=str(p.parent))
    try:
        with os.fdopen(fd, 'wb') as stream:
            stream.write(data); stream.flush(); os.fsync(stream.fileno())
        os.chmod(tmp, permissions)
        os.replace(tmp, str(p))
    finally:
        if os.path.exists(tmp): os.unlink(tmp)
def prepare(site, package):
    for name, expected in DEPENDENCIES:
        if digest(read(safe(site, name))) != expected:
            raise RuntimeError('DEPENDENCIA DIFERENTE: ' + name)
        print('DEPENDENCIA OK: ' + name)
    if not os.access(str(site/'storage/cache/denue'), os.W_OK):
        raise RuntimeError('CACHE SIN ESCRITURA')
    php = shutil.which('php')
    if not php or subprocess.call([php, '-r', "exit(PHP_VERSION_ID >= 80100 && function_exists('curl_multi_init') && function_exists('openssl_x509_parse') ? 0 : 1);"]):
        raise RuntimeError('PHP con cURL y OpenSSL no disponible')
    rows = []
    for name, before, after in FILES:
        dest = safe(site, name)
        current = read(dest)
        target = safe(package, name)
        data = read(target)
        if digest(data) != after: raise RuntimeError('PAQUETE ALTERADO: ' + name)
        if digest(current) not in (before, after): raise RuntimeError('VERSION DIFERENTE: ' + name)
        subprocess.check_call([php, '-l', str(target)])
        rows.append((name, dest, current, data, mode(dest) if current is not None else 0o644))
        print(('YA INSTALADO: ' if current == data else 'COMPATIBLE: ') + name)
    return rows
@contextlib.contextmanager
def lock(root):
    root.mkdir(parents=True, exist_ok=True)
    p = root/'.publicacion.lock'
    fd = os.open(str(p), os.O_WRONLY|os.O_CREAT|os.O_EXCL, 0o600)
    os.close(fd)
    try: yield
    finally: p.unlink()
def apply(site, package, root):
    with lock(root):
        rows = [r for r in prepare(site, package) if r[2] != r[3]]
        if not rows: print('YA INSTALADO: sin cambios'); return
        backup = Path(tempfile.mkdtemp(prefix='denue-', dir=str(root)))
        os.chmod(str(backup), 0o700)
        meta = {'site':str(site), 'files':[]}
        for name, dest, old, new, permissions in rows:
            if old is not None:
                original = backup/name
                original.parent.mkdir(parents=True, exist_ok=True)
                original.write_bytes(old)
            meta['files'].append({'name':name,'before':digest(old),'after':digest(new),'mode':permissions})
        (backup/'respaldo.json').write_text(json.dumps(meta), encoding='utf-8')
        installed = []
        try:
            for name, dest, old, new, permissions in rows:
                if read(dest) != old: raise RuntimeError('Cambio concurrente: '+name)
                atomic(dest, new, permissions)
                installed.append((name,dest,old,new,permissions))
                if read(dest) != new: raise RuntimeError('Verificación fallida: '+name)
        except BaseException:
            for name,dest,old,new,permissions in reversed(installed):
                if read(dest) != new:
                    print('REVISAR RESTAURACION: '+name, file=sys.stderr); continue
                if old is None: dest.unlink()
                else: atomic(dest, old, permissions)
            raise
        print('APLICADO: tres archivos DENUE. Sin SQL. RESPALDO: '+str(backup))
def restore(site, root, backup):
    if root not in backup.parents: raise RuntimeError('Respaldo fuera del directorio privado')
    with lock(root):
        meta=json.loads((backup/'respaldo.json').read_text(encoding='utf-8'))
        if meta['site'] != str(site): raise RuntimeError('Respaldo ajeno')
        known={n:(b,a) for n,b,a in FILES}
        plan=[]
        for row in meta['files']:
            n=row['name']
            if n not in known or (row['before'], row['after']) != known[n]:
                raise RuntimeError('Respaldo incompatible')
            p=safe(site,n)
            old=read(safe(backup,n)) if row['before'] is not None else None
            if digest(old)!=row['before'] or digest(read(p))!=row['after']:
                raise RuntimeError('Archivo o respaldo cambiado: '+n)
            plan.append((p,old,row['mode']))
        for p,old,permissions in reversed(plan):
            if old is None: p.unlink()
            else: atomic(p,old,permissions)
        print('RESTAURADO: sin SQL')
def main():
    parser=argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--site',required=True)
    parser.add_argument('--package',required=True)
    parser.add_argument('--backup-root',required=True)
    action=parser.add_mutually_exclusive_group(required=True)
    action.add_argument('--check',action='store_true')
    action.add_argument('--apply',action='store_true')
    action.add_argument('--restore')
    args=parser.parse_args()
    site,package,root=[Path(x).resolve() for x in (args.site,args.package,args.backup_root)]
    if not (site/'index.php').is_file(): raise RuntimeError('Sitio sin index.php')
    if root==site or site in root.parents or package==site or site in package.parents:
        raise RuntimeError('Paquete y respaldo deben estar fuera del sitio')
    if args.restore: restore(site,root,Path(args.restore).resolve())
    elif args.apply: apply(site,package,root)
    else:
        prepare(site,package)
        print('CHECK OK: sin cambios en el sitio, sin SQL y sin consultas a INEGI.')
if __name__=='__main__':
    try: main()
    except Exception as e:
        print('ERROR: '+str(e),file=sys.stderr)
        sys.exit(1)
