#!/usr/bin/env python3
"""Instalación selectiva del importador CSV/XLSX de Meta. Sin SQL ni cambios de telefonía."""
import argparse
import datetime
import hashlib
import json
import os
from pathlib import Path
import shutil
import subprocess
import sys
import tempfile
from urllib.request import urlopen

REPO='davidvaldez050708-dot/SistemaComercialIMPE'
REVISION='e4aef1e2685e2c6e7d82cedb7d23f87e1deb9652'
# Hashes blob de Git de las versiones YA instaladas y la versión nueva.
# Aceptar sólo la Fase 1 original o la corrección visual compacta validada.
FILES={
 'app/controllers/ProspectoController.php': (['7c645193e82b64d0d38b659a424ea2490e7fb294','dce880d3ea5a1a10b5e4207523cb9864305d9b89'], '4cdc3b2f53d68be6ea3bb0e4e86a43f5be5b55a0'),
 'app/models/RolModel.php': (['42d2021464a44de46484165273f172e11955921f'], 'b58100296b9699d242c1d3a4b13e8055f214ae6e'),
 'app/helpers/AdminRolePolicy.php': (['094ad89ddb5ceb7eef81fc0175682f973b7260a2'], '87024131a5d5bb8d2bfe7be9440c232422938d0f'),
 'app/views/prospectos/central.php': (['034a6454097cc9f4044c990995053cded20dd3c8'], 'e2f70273efc2b323c493940048d32ea66aa215f0'),
 'app/services/ProspectoMetaArchivo.php': ([], '7bca491418bfff4c507c5ab56cad83cc2d73034c'),
 'app/services/ProspectoMetaImportador.php': ([None,'c32d492b07d8515764365b6e5941028053904e31'], 'c959be50bb9fe03ea4b02d9c632a16f6b979a3cf'),
 'app/views/prospectos/partials/importar_meta.php': ([], '7c2d65a0543497d292fb91cded7872be2973f32e'),
 'public/css/prospectos_importador_meta.css': ([], '1cb23c9df89164b1e26c5c0df7b8c9155bd1f8f0'),
 'public/javascript/prospectos_importador_meta.js': ([], '7a36dddfff26c5afc29f7e49ff88711ecbe47229'),
}


def git_hash(data):
    return hashlib.sha1(('blob {}\0'.format(len(data))).encode('ascii')+data).hexdigest()


def local(site,name):
    path=Path(name)
    if path.is_absolute() or '..' in path.parts or '\\' in name:
        raise RuntimeError('Ruta fuera del paquete.')
    dest=site/path
    if dest.is_symlink() or site not in dest.resolve().parents:
        raise RuntimeError('Destino fuera del sitio: '+name)
    return dest


def read(path):
    return path.read_bytes() if path.is_file() else None


def atomic(dest,data,mode=0o644):
    dest.parent.mkdir(parents=True,exist_ok=True)
    fd,tmp=tempfile.mkstemp(prefix='.prospecto-panel-',dir=str(dest.parent))
    try:
        with os.fdopen(fd,'wb') as f:
            f.write(data)
            f.flush()
            os.fsync(f.fileno())
        os.chmod(tmp,mode)
        os.replace(tmp,str(dest))
    finally:
        if os.path.exists(tmp):
            os.unlink(tmp)


def fetch(name,expected):
    url='https://raw.githubusercontent.com/{}/{}/{}'.format(REPO,REVISION,name)
    with urlopen(url,timeout=30) as response:
        content=response.read(1024*1024+1)
    if len(content)>1024*1024 or git_hash(content)!=expected:
        raise RuntimeError('Paquete remoto no coincide con su revisión: '+name)
    return content


def lint(name,content):
    suffix=Path(name).suffix
    if suffix not in ('.php','.js'):
        return
    program='php' if suffix=='.php' else 'node'
    if not shutil.which(program):
        if suffix=='.php':
            raise RuntimeError('PHP CLI no disponible.')
        return
    fd,tmp=tempfile.mkstemp(prefix='prospectos-panel-',suffix=suffix)
    try:
        with os.fdopen(fd,'wb') as f:
            f.write(content)
        cmd=[program,'-l',tmp] if suffix=='.php' else [program,'--check',tmp]
        proc=subprocess.run(cmd,stdout=subprocess.PIPE,stderr=subprocess.PIPE)
        if proc.returncode:
            raise RuntimeError('El archivo no supera la comprobación de sintaxis: '+name)
    finally:
        if os.path.exists(tmp):
            os.unlink(tmp)


def restore(site,backup):
    if site==backup or site in backup.parents:
        raise RuntimeError('Respaldo dentro del sitio.')
    meta=json.loads((backup/'respaldo.json').read_text(encoding='utf-8'))
    rows=meta.get('files')
    if meta.get('site')!=str(site) or not isinstance(rows,list) or not rows or not set(x['path'] for x in rows).issubset(set(FILES)):
        raise RuntimeError('Respaldo ajeno o inválido.')
    plan=[]
    for x in rows:
        name=x['path']
        target=local(site,name)
        current=read(target)
        if current is None or git_hash(current)!=x['after']:
            raise RuntimeError('No restaurar: versión modificada después del despliegue: '+name)
        original=read(local(backup/'original',name)) if x['before'] else None
        if x['before'] and (original is None or git_hash(original)!=x['before']):
            raise RuntimeError('No restaurar: respaldo dañado: '+name)
        plan.append((target,original,int(x['mode'])))
    for target,original,mode in reversed(plan):
        if original is None:
            target.unlink()
        else:
            atomic(target,original,mode)
    print('RESTAURADO: código anterior. Sin cambios de SQL ni datos.')

def check_extensions():
    if not shutil.which('php'):
        raise RuntimeError('PHP CLI no disponible.')
    code="exit(class_exists('ZipArchive') && class_exists('DOMDocument') && function_exists('mb_check_encoding') && function_exists('iconv') ? 0 : 1);"
    proc=subprocess.run(['php','-r',code],stdout=subprocess.PIPE,stderr=subprocess.PIPE)
    if proc.returncode:
        raise RuntimeError('Faltan extensiones PHP ZIP, DOM, mbstring o iconv; no se publica el importador Excel.')


def main():
    parser=argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--site',required=True)
    parser.add_argument('--backup-root',default=str(Path.home()/'respaldos-impe-prospectos-meta'))
    actions=parser.add_mutually_exclusive_group(required=True)
    actions.add_argument('--check',action='store_true')
    actions.add_argument('--apply',action='store_true')
    actions.add_argument('--restore')
    args=parser.parse_args()
    site=Path(args.site).resolve()
    backup_root=Path(args.backup_root).resolve()
    if not (site/'index.php').is_file() or backup_root==site or site in backup_root.parents:
        raise RuntimeError('Sitio o destino del respaldo inválido.')
    if args.restore:
        restore(site,Path(args.restore).resolve())
        return
    for dep in ['config/db_connection.php','app/services/ProspectoCentralSchema.php',
                'public/javascript/prospectos_fase1.js','public/javascript/prospectos_central.js',
                'app/services/ProspectoCentralRepository.php',
                'app/views/prospectos/partials/ficha_panel.php']:
        if read(local(site,dep)) is None:
            raise RuntimeError('Dependencia del módulo ausente: '+dep)
    check_extensions()
    plan=[]
    for name,(known,desired) in FILES.items():
        path=local(site,name)
        content=read(path)
        current=git_hash(content) if content is not None else None
        if current==desired:
            print('YA INSTALADO: '+name)
            continue
        if current not in known and not (current is None and not known):
            raise RuntimeError('VERSIÓN DIFERENTE: '+name+'. Sitio intacto.')
        plan.append((name,path,content,desired,path.stat().st_mode & 0o777 if content is not None else 0o644))
        print(('NUEVO: ' if content is None else 'COMPATIBLE: ')+name)
    staged=[]
    for name,path,original,wanted,mode in plan:
        data=fetch(name,wanted)
        lint(name,data)
        staged.append((name,path,original,data,mode))
    if args.check:
        print('CHECK OK: versiones y sintaxis verificadas. Sin escrituras ni SQL.')
        return
    if not staged:
        print('Sin cambios: paquete ya instalado. Sin SQL.')
        return
    for name,path,original,data,mode in staged:
        if read(path)!=original:
            raise RuntimeError('Cambio concurrente en: '+name)
    backup_root.mkdir(parents=True,exist_ok=True)
    os.chmod(str(backup_root),0o700)
    backup=Path(tempfile.mkdtemp(prefix=datetime.datetime.utcnow().strftime('%Y%m%d-%H%M%S-'),dir=str(backup_root)))
    rows=[]
    for name,path,original,data,mode in staged:
        if original is not None:
            atomic(local(backup/'original',name),original,0o600)
        rows.append({'path':name,'before':git_hash(original) if original is not None else None,'after':git_hash(data),'mode':mode})
    atomic(backup/'respaldo.json',json.dumps({'site':str(site),'files':rows},indent=2).encode('utf-8'),0o600)
    applied=[]
    try:
        for name,path,original,data,mode in staged:
            if read(path)!=original:
                raise RuntimeError('Cambio concurrente en: '+name)
            atomic(path,data,mode)
            applied.append((path,original,mode))
            if git_hash(read(path))!=git_hash(data):
                raise RuntimeError('Verificación posterior fallida: '+name)
    except Exception:
        for path,original,mode in reversed(applied):
            if original is None:
                path.unlink()
            else:
                atomic(path,original,mode)
        raise
    print('APLICADO: importador Meta con autorización por Roles y permisos. Instalación de archivos sin SQL ni cambios de telefonía.')
    print('RESPALDO: '+str(backup))


if __name__=='__main__':
    try:
        main()
    except Exception as exc:
        print('ERROR: '+str(exc),file=sys.stderr)
        sys.exit(1)
