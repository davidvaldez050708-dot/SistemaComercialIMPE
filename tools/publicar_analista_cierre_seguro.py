#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Publica sólo el cierre de llamadas salientes del Analista.
Preflight por fragmentos únicos, sin SQL ni cambios en audio o transferencias.
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

def read(path):
    return path.read_bytes() if path.is_file() else None

def sha(data):
    return None if data is None else hashlib.sha256(data).hexdigest()

def blob(data):
    return None if data is None else hashlib.sha1(
        ('blob ' + str(len(data)) + '\0').encode('ascii') + data).hexdigest()

def safe(root, name):
    part=Path(name)
    if part.is_absolute() or '..' in part.parts or '\\' in name:
        raise RuntimeError('Ruta inválida: '+name)
    path=root / part
    if root not in path.resolve().parents:
        raise RuntimeError('Ruta fuera del directorio permitido: '+name)
    node=path
    while node!=root:
        if node.is_symlink():
            raise RuntimeError('Ruta simbólica no permitida: '+name)
        node=node.parent
    return path

def apply_hunks(data, row):
    text=data
    for i, change in enumerate(row['hunks']):
        before=change['before'].encode('utf-8')
        after=change['after'].encode('utf-8')
        if b'\r\n' in text and b'\n' in before:
            before=before.replace(b'\n',b'\r\n')
            after=after.replace(b'\n',b'\r\n')
        count_before=text.count(before)
        count_after=text.count(after)
        if count_before==1 and count_after==0:
            text=text.replace(before,after,1)
        elif count_before==0 and count_after==1:
            pass
        else:
            raise RuntimeError('CONFLICTO: fragmento '+str(i+1)+' de '+row['path']+
                ' modificado o ambiguo; sitio intacto.')
    return text

def prepare(site, manifest):
    if manifest.get('schema')!='impe.salientes.cierre.selectivo.v1' or len(manifest.get('files',[]))!=4:
        raise RuntimeError('Manifest no reconocido.')
    paths=[row['path'] for row in manifest['files']]
    if len(paths)!=len(set(paths)) or sorted(paths)!=sorted([
        'app/services/TelefoniaSalienteAnalistaEstado.php',
        'app/services/TelefoniaEntrantesAnalistaService.php',
        'app/views/seguimiento_vinculacion/llamadas_entrantes.php',
        'public/javascript/telefonia_analista_historial_pasivo.js'
    ]):
        raise RuntimeError('El manifest incluye rutas no autorizadas.')
    for name in ['app/services/ZadarmaCallLookupService.php',
                 'prueba_telefonia/api/zadarma_estado_llamada.php']:
        if read(safe(site,name)) is None:
            raise RuntimeError('DEPENDENCIA AUSENTE: '+name)
    rows=[]
    for row in manifest['files']:
        path=safe(site,row['path'])
        original=read(path)
        if original is None:
            raise RuntimeError('ARCHIVO AUSENTE: '+row['path'])
        output=apply_hunks(original,row)
        mode=path.stat().st_mode & 0o777
        rows.append({'name':row['path'],'path':path,'before':original,
                     'after':output,'mode':mode,'target_mode':0o644})
        print(('YA INSTALADO: ' if output==original and mode==0o644
               else 'COMPATIBLE: ')+row['path'])
    for row in rows:
        suffix=Path(row['name']).suffix
        if suffix=='.php':
            with tempfile.TemporaryDirectory(prefix='impe-cierre-lint-') as temp:
                script=Path(temp)/'archivo.php'
                script.write_bytes(row['after'])
                proc=subprocess.run(['php','-l',str(script)],stdout=subprocess.PIPE,stderr=subprocess.PIPE)
                if proc.returncode:
                    raise RuntimeError('ERROR PHP: '+row['name'])
        if suffix=='.js':
            node=shutil.which('node')
            if node:
                with tempfile.TemporaryDirectory(prefix='impe-cierre-lint-') as temp:
                    script=Path(temp)/'archivo.js'
                    script.write_bytes(row['after'])
                    proc=subprocess.run([node,'--check',str(script)],stdout=subprocess.PIPE,stderr=subprocess.PIPE)
                    if proc.returncode:
                        raise RuntimeError('ERROR JavaScript: '+row['name'])
            else:
                print('AVISO: Node.js no disponible; JavaScript validado en GitHub Actions.')
    return rows

def atomic(path,content,mode):
    fd,tmp=tempfile.mkstemp(prefix='.impe-cierre-safe-',dir=str(path.parent))
    try:
        with os.fdopen(fd,'wb') as f:
            f.write(content)
            f.flush()
            os.fsync(f.fileno())
        os.chmod(tmp,mode)
        os.replace(tmp,str(path))
    finally:
        if os.path.exists(tmp):
            os.unlink(tmp)

@contextlib.contextmanager
def lock(root):
    root.mkdir(parents=True,exist_ok=True,mode=0o700)
    path=root/'.impe-cierre-safe.lock'
    fd=os.open(str(path),os.O_CREAT|os.O_EXCL|os.O_WRONLY,0o600)
    try:
        os.write(fd,str(os.getpid()).encode('ascii'))
        os.close(fd)
        yield
    finally:
        path.unlink()

def publish(site,root,manifest):
    # Rechazar incompatibilidades sin crear directorios ni modificar el CRM.
    prepare(site,manifest)
    with lock(root):
        rows=prepare(site,manifest)
        changed=[x for x in rows if x['before']!=x['after'] or x['mode']!=0o644]
        if not changed:
            print('YA INSTALADO: sin cambios.')
            return
        folder=Path(tempfile.mkdtemp(prefix=datetime.datetime.utcnow().strftime('%Y%m%d-%H%M%S-'),dir=str(root)))
        os.chmod(str(folder),0o700)
        metadata={'site':str(site),'files':[]}
        for row in changed:
            dest=safe(folder,'original/'+row['name'])
            dest.parent.mkdir(parents=True,exist_ok=True)
            dest.write_bytes(row['before'])
            metadata['files'].append({'name':row['name'],'before':sha(row['before']),
                'after':sha(row['after']),'mode':row['mode'],'after_mode':row['target_mode']})
        (folder/'respaldo.json').write_text(json.dumps(metadata,ensure_ascii=False,indent=2),encoding='utf-8')
        for row in changed:
            if read(row['path'])!=row['before'] or row['path'].stat().st_mode&0o777!=row['mode']:
                raise RuntimeError('CAMBIO CONCURRENTE: '+row['name']+'. Sin aplicar.')
        applied=[]
        try:
            for row in changed:
                if read(row['path'])!=row['before']:
                    raise RuntimeError('Cambio externo concurrente: '+row['name'])
                atomic(row['path'],row['after'],row['target_mode'])
                applied.append(row)
                if read(row['path'])!=row['after']:
                    raise RuntimeError('Fallo verificando '+row['name'])
        except BaseException:
            for row in reversed(applied):
                if read(row['path'])==row['after']:
                    atomic(row['path'],row['before'],row['mode'])
                else:
                    print('REVISAR MANUALMENTE: '+row['name'],file=sys.stderr)
            raise
        print('APLICADO: cierre saliente, resultado sin respuesta y conciliación PBX.')
        print('RESPALDO: '+str(folder))
        print('Sin SQL ni cambios de grabaciones, SDK o transferencias.')

def restore(site,root,dirname):
    folder=Path(dirname).resolve()
    if root not in folder.parents or site in folder.parents:
        raise RuntimeError('Respaldo fuera de la ruta privada.')
    with lock(root):
        data=json.loads((folder/'respaldo.json').read_text(encoding='utf-8'))
        if data.get('site')!=str(site) or not data.get('files'):
            raise RuntimeError('Respaldo no corresponde al CRM.')
        restores=[]
        for entry in data['files']:
            if entry['name'] not in [
                'app/services/TelefoniaSalienteAnalistaEstado.php',
                'app/services/TelefoniaEntrantesAnalistaService.php',
                'app/views/seguimiento_vinculacion/llamadas_entrantes.php',
                'public/javascript/telefonia_analista_historial_pasivo.js']:
                raise RuntimeError('Archivo fuera de la entrega.')
            path=safe(site,entry['name'])
            original=read(safe(folder,'original/'+entry['name']))
            if sha(original)!=entry['before'] or sha(read(path))!=entry['after']:
                raise RuntimeError('CONFLICTO de reversión: '+entry['name'])
            if path.stat().st_mode&0o777!=entry['after_mode']:
                raise RuntimeError('Permisos cambiados: '+entry['name'])
            restores.append((path,original,entry['mode']))
        for path,contents,mode in reversed(restores):
            atomic(path,contents,mode)
        print('RESTAURADO: versión anterior de los cuatro archivos, sin SQL.')

def main():
    if sys.version_info<(3,6):
        raise RuntimeError('Se requiere Python 3.6 o posterior.')
    parser=argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--site',required=True)
    parser.add_argument('--backup-root',required=True)
    parser.add_argument('--manifest',default=str(Path(__file__).parent/'analista_cierre_seguro_manifest.json'))
    mode=parser.add_mutually_exclusive_group(required=True)
    mode.add_argument('--check',action='store_true')
    mode.add_argument('--apply',action='store_true')
    mode.add_argument('--restore')
    opts=parser.parse_args()
    site=Path(opts.site).resolve()
    backups=Path(opts.backup_root).resolve()
    if not (site/'index.php').is_file():
        raise RuntimeError('No se encontró el CRM en --site.')
    if site==backups or site in backups.parents or site in backups.parents:
        raise RuntimeError('Los respaldos deben quedar fuera del sitio.')
    manifest=json.loads(Path(opts.manifest).read_text(encoding='utf-8'))
    if opts.restore:
        restore(site,backups,opts.restore)
    elif opts.apply:
        publish(site,backups,manifest)
    else:
        prepare(site,manifest)
        print('CHECK OK: cuatro archivos y dependencias; sin modificar el sitio ni SQL.')

if __name__=='__main__':
    try:
        main()
    except Exception as e:
        print('ERROR: '+str(e),file=sys.stderr)
        sys.exit(1)
