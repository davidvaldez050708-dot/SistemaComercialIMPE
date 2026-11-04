#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Publicador selectivo y reversible: historial y resultado superpuesto del Analista.
No ejecuta SQL, no modifica Zadarma/SIP ni llamadas desde Seguimiento.
Compatible con Python 3.6 (Hostinger).
"""
from __future__ import print_function
import argparse
import contextlib
import datetime
import hashlib
import json
import os
from pathlib import Path
import shutil
import subprocess
import sys
import tempfile

ALLOW = {
    'app/views/telefonia/shell.php',
    'public/javascript/telefonia_analista_resultado_superpuesto.js',
    'public/javascript/telefonia_analista_historial_pasivo.js',
    'public/javascript/seguimiento_entrantes_analista.js',
    'app/services/TelefoniaEntrantesAnalistaService.php',
    'app/views/seguimiento_vinculacion/llamadas_entrantes.php',
    'app/services/TelefoniaMisLlamadasService.php',
    'app/views/telefonia/mis_llamadas.php'
}

def checksum(data):
    return hashlib.sha256(data).hexdigest() if data is not None else None

def git_blob(data):
    if data is None:
        return None
    raw=('blob %d\0' % len(data)).encode('ascii')+data
    return hashlib.sha1(raw).hexdigest()

def inside(root, name):
    part=Path(name)
    if part.is_absolute() or '..' in part.parts or '\\' in name:
        raise ValueError('Ruta no permitida: '+name)
    target=root/part
    if root not in target.resolve().parents:
        raise ValueError('Ruta fuera de CRM: '+name)
    node=target
    while node!=root:
        if node.is_symlink():
            raise ValueError('Ruta simbólica no permitida: '+name)
        node=node.parent
    return target

def read(path):
    return path.read_bytes() if path.is_file() else None

def proposed(data, item):
    if item['action']=='create':
        candidate=item['content'].encode('utf-8')
        if data is not None and data != candidate:
            raise ValueError('CONFLICTO: archivo ya existente distinto: '+item['path'])
    elif item['action']=='patch':
        if data is None:
            raise ValueError('CONFLICTO: no existe: '+item['path'])
        candidate=data
        for i, h in enumerate(item['changes']):
            before=h['before'].encode('utf-8')
            after=h['after'].encode('utf-8')
            if b'\r\n' in candidate:
                before=before.replace(b'\n',b'\r\n')
                after=after.replace(b'\n',b'\r\n')
            if candidate.count(after)==1:
                continue
            if candidate.count(before)!=1 or candidate.count(after)!=0:
                raise ValueError('CONFLICTO: personalización no reconocida en '+item['path']+' fragmento '+str(i+1))
            candidate=candidate.replace(before,after,1)
    else:
        raise ValueError('Acción de manifest desconocida')
    if data is None or git_blob(data)==item.get('beforeBlob'):
        if git_blob(candidate)!=item.get('afterBlob'):
            raise ValueError('INTEGRIDAD: código nuevo distinto del esperado en '+item['path'])
    return candidate

def verify(site,manifest):
    rows=manifest.get('files')
    if manifest.get('schema')!='impe.analista.historial.overlay.v1' or not isinstance(rows,list):
        raise ValueError('Manifest incompatible')
    names=[item['path'] for item in rows]
    if len(names)!=len(ALLOW) or set(names)!=ALLOW:
        raise ValueError('Manifest incluye archivos no autorizados')
    ready=[]
    for item in rows:
        path=inside(site,item['path'])
        before=read(path)
        after=proposed(before,item)
        mode=(path.stat().st_mode & 0o777) if before is not None else 0o644
        ready.append({'path':path,'name':item['path'],'before':before,
                      'after':after,'mode':mode})
        print(('YA INSTALADO: ' if before==after else 'COMPATIBLE: ')+item['path'])
    for item in ready:
        suffix=item['path'].suffix
        cmd=['php','-l'] if suffix=='.php' else (
            ['node','--check'] if suffix=='.js' and shutil.which('node') else [])
        if not cmd:
            if suffix=='.js':
                print('AVISO: Node no disponible; JS requiere CI aprobado antes de publicar.')
            continue
        with tempfile.TemporaryDirectory(prefix='impe-analista-lint-') as temp:
            file=Path(temp)/('verify'+suffix)
            file.write_bytes(item['after'])
            p=subprocess.run(cmd+[str(file)],stdout=subprocess.PIPE,stderr=subprocess.PIPE)
            if p.returncode:
                raise ValueError('SINTAXIS: '+item['name']+': '+p.stderr.decode('utf-8','replace')[:450])
    return ready

def atomic(path,data,mode):
    fd,tmp=tempfile.mkstemp(prefix='.impe-analista-',dir=str(path.parent))
    try:
        with os.fdopen(fd,'wb') as f:
            f.write(data);f.flush();os.fsync(f.fileno())
        os.chmod(tmp,mode)
        os.replace(tmp,str(path))
    finally:
        if os.path.exists(tmp):
            os.unlink(tmp)

@contextlib.contextmanager
def lock(root):
    root.mkdir(parents=True,exist_ok=True,mode=0o700)
    p=root/'.impe-historial-overlay.lock'
    fd=os.open(str(p),os.O_CREAT|os.O_EXCL|os.O_WRONLY,0o600)
    try:
        os.write(fd,str(os.getpid()).encode('ascii'))
        os.close(fd)
        yield
    finally:
        try: p.unlink()
        except OSError: pass

def publish(site,backups,manifest):
    # No crear carpetas, siquiera de backup, si la compatibilidad falla.
    verify(site,manifest)
    with lock(backups):
        rows=verify(site,manifest)
        changed=[x for x in rows if x['before']!=x['after']]
        if not changed:
            print('YA INSTALADO: sin escrituras')
            return
        archive=Path(tempfile.mkdtemp(prefix=datetime.datetime.utcnow().strftime('%Y%m%d-%H%M%S-'),dir=str(backups)))
        os.chmod(str(archive),0o700)
        files=[]
        for item in changed:
            if item['before'] is not None:
                original=inside(archive,'original/'+item['name'])
                original.parent.mkdir(parents=True,exist_ok=True)
                original.write_bytes(item['before'])
            files.append({'path':item['name'],'before':checksum(item['before']),
                          'after':checksum(item['after']),'mode':item['mode']})
        (archive/'respaldo.json').write_text(json.dumps({
            'site':str(site),'manifest_head':manifest.get('head'),
            'files':files
        },indent=2),encoding='utf-8')
        for item in changed:
            if read(item['path'])!=item['before']:
                raise RuntimeError('CAMBIO CONCURRENTE: '+item['name']+'. No se aplicó nada.')
        installed=[]
        try:
            for item in changed:
                if read(item['path'])!=item['before']:
                    raise RuntimeError('CAMBIO CONCURRENTE: '+item['name'])
                item['path'].parent.mkdir(parents=True,exist_ok=True)
                atomic(item['path'],item['after'],item['mode'])
                installed.append(item)
                if read(item['path'])!=item['after']:
                    raise RuntimeError('Verificación fallida: '+item['name'])
        except BaseException:
            for item in reversed(installed):
                if read(item['path'])==item['after']:
                    if item['before'] is None:
                        item['path'].unlink()
                    else:
                        atomic(item['path'],item['before'],item['mode'])
                else:
                    print('REVISAR MANUALMENTE: '+item['name'],file=sys.stderr)
            raise
        print('APLICADO: '+str(len(changed))+' archivos. RESPALDO: '+str(archive))
        print('Sin SQL, sin borrar grabaciones ni modificar motor WebRTC.')

def restore(site,backups,folder):
    archive=Path(folder).resolve()
    if backups not in archive.parents:
        raise ValueError('Respaldo fuera del directorio autorizado')
    with lock(backups):
        data=json.loads((archive/'respaldo.json').read_text(encoding='utf-8'))
        if data.get('site')!=str(site):
            raise ValueError('Respaldo pertenece a otro sitio')
        checked=[]
        for item in data.get('files',[]):
            if item['path'] not in ALLOW:
                raise ValueError('Respaldo contiene ruta no autorizada')
            path=inside(site,item['path'])
            before=read(inside(archive,'original/'+item['path'])) if item['before'] else None
            if checksum(before)!=item['before'] or checksum(read(path))!=item['after']:
                raise ValueError('CONFLICTO: archivo posterior distinto: '+item['path'])
            checked.append((path,before,int(item['mode'])))
        for path,before,mode in reversed(checked):
            if before is None: path.unlink()
            else: atomic(path,before,mode)
        print('RESTAURADO: sólo archivos de esta entrega; sin SQL.')

def main():
    parser=argparse.ArgumentParser()
    parser.add_argument('--site',required=True)
    parser.add_argument('--backup-root',required=True)
    parser.add_argument('--manifest',default=str(Path(__file__).parent/'analista_historial_overlay_manifest.json'))
    mode=parser.add_mutually_exclusive_group(required=True)
    mode.add_argument('--check',action='store_true')
    mode.add_argument('--apply',action='store_true')
    mode.add_argument('--restore')
    opt=parser.parse_args()
    site=Path(opt.site).resolve()
    backup=Path(opt.backup_root).resolve()
    if not (site/'index.php').is_file():
        raise ValueError('Ruta --site no es CRM')
    if backup==site or site in backup.parents or backup in site.parents:
        raise ValueError('Respaldo debe estar fuera de la instalación')
    manifest=json.loads(Path(opt.manifest).read_text(encoding='utf-8'))
    if opt.restore: restore(site,backup,opt.restore)
    elif opt.apply: publish(site,backup,manifest)
    else:
        verify(site,manifest)
        print('CHECK OK: 8 archivos, compatibilidad y sintaxis; sin escrituras ni SQL.')

if __name__=='__main__':
    try: main()
    except Exception as e:
        print('ERROR: '+str(e),file=sys.stderr)
        sys.exit(1)
