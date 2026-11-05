#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Publicador reversible de cinco archivos para oficios de Vinculación.
--check es estrictamente de solo lectura, sin SQL, documentos ni correo.
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

ALLOW={
    'app/controllers/OficioVinculacionController.php',
    'app/services/OficioPreviewService.php',
    'app/services/OficioDocxPdfService.php',
    'public/javascript/oficios_vinculacion.js',
    'public/css/oficios_selector_compacto.css'
}

def read(path):
    return path.read_bytes() if path.is_file() else None

def sha(data):
    return hashlib.sha256(data).hexdigest() if data is not None else None

def git_blob(data):
    if data is None:
        return None
    return hashlib.sha1(('blob %d\0' % len(data)).encode('ascii')+data).hexdigest()

def safe(root,name):
    part=Path(name)
    if part.is_absolute() or '..' in part.parts or '\\' in name:
        raise RuntimeError('Ruta de archivo no permitida: '+name)
    path=root/part
    if root not in path.resolve().parents:
        raise RuntimeError('Ruta fuera de la carpeta autorizada: '+name)
    pos=path
    while pos!=root:
        if pos.is_symlink():
            raise RuntimeError('No utilizar symlinks para modificar archivos: '+name)
        pos=pos.parent
    return path

def apply_hunks(raw,item):
    out=raw
    for idx,h in enumerate(item['changes']):
        before=h['before'].encode('utf-8')
        after=h['after'].encode('utf-8')
        if b'\r\n' in out:
            before=before.replace(b'\n',b'\r\n')
            after=after.replace(b'\n',b'\r\n')
        # Una inserción puede compartir texto con el hunk anterior.
        # El estado de salida completo prevalece para ser idempotente.
        if out.count(after)==1:
            continue
        if out.count(before)!=1 or out.count(after)!=0:
            raise RuntimeError('CONFLICTO: personalización del archivo '+item['path']+
                               ' en fragmento '+str(idx+1)+'; no se modifica el sitio.')
        out=out.replace(before,after,1)
    return out

# Servicio DOCX personalizado comprobado mediante lectura de Hostinger.
# El parámetro $prepararPlantilla indica:
# TRUE = rellenar una plantilla nueva; FALSE = usar generacion_* ya procesada.
# No sustituirlo por un parámetro de significado contrario.
HOSTINGER_DOCX_SHA256='ff3720e6905ad92ddd4515f6e8a793d68a4cfb9a29476ba2862abd75c2241440'

def is_existing_hostinger_docx(raw):
    if raw is None or sha(raw)!=HOSTINGER_DOCX_SHA256:
        return False
    method=b'public function generarDocx(array $vista, $prepararPlantilla = false)'
    start=raw.find(method)
    end=raw.find(b'public function validarPlantillaProcesable',start+len(method))
    if start<0 or end<=start or raw.count(method)!=1:
        raise RuntimeError('CONFLICTO: la firma personalizada DOCX fue modificada.')
    chunk=raw[start:end]
    required=[
        b'$procesada = !$prepararPlantilla && preg_match(',
        b'$rellenado = $procesada ?',
        b"str_replace('\\\\', '/', (string)($vista['archivo_plantilla'] ?? ''))",
        b'$this->rellenarConTemplateProcessor($rutaDocx, $vista)'
    ]
    if any(token not in chunk for token in required):
        raise RuntimeError('CONFLICTO: comportamiento personalizado DOCX diferente del verificado.')
    return True


def verify(site,manifest):
    if manifest.get('schema')!='impe.vinculacion.oficios.modal.documento-final.v1':
        raise RuntimeError('Manifest no reconocido.')
    files=manifest.get('files')
    if not isinstance(files,list) or len(files)!=5 or {x['path'] for x in files}!=ALLOW:
        raise RuntimeError('El manifest incluye rutas incorrectas.')
    results=[]
    for row in files:
        path=safe(site,row['path'])
        previous=read(path)
        if previous is None:
            raise RuntimeError('Falta archivo de sistema: '+row['path'])
        conservar=(row['path']=='app/services/OficioDocxPdfService.php'
                  and is_existing_hostinger_docx(previous))
        updated=previous if conservar else apply_hunks(previous,row)
        if git_blob(previous)==row['beforeBlob'] and git_blob(updated)!=row['afterBlob']:
            raise RuntimeError('INTEGRIDAD: salida inesperada en '+row['path'])
        results.append({'name':row['path'],'path':path,'before':previous,'after':updated,
                        'mode':path.stat().st_mode & 0o777})
        print(('COMPATIBLE PERSONALIZADO (SIN MODIFICAR): ' if conservar else
               ('YA INSTALADO: ' if previous==updated else 'COMPATIBLE: '))+row['path'])
    for row in results:
        suffix=row['path'].suffix
        cmd=(['php','-l'] if suffix=='.php' else
             (['node','--check'] if suffix=='.js' and shutil.which('node') else []))
        if not cmd:
            if suffix=='.js':
                print('AVISO: Node.js ausente; sintaxis JS probada en CI de GitHub.')
            continue
        with tempfile.TemporaryDirectory(prefix='impe-oficios-lint-') as d:
            file=Path(d)/('archivo'+suffix)
            file.write_bytes(row['after'])
            p=subprocess.run(cmd+[str(file)],stdout=subprocess.PIPE,stderr=subprocess.PIPE)
            if p.returncode:
                raise RuntimeError('SINTAXIS: '+row['name']+' '+p.stderr.decode('utf-8','replace')[:450])
    return results

def atomic(path,content,mode):
    fd,tmp=tempfile.mkstemp(prefix='.impe-oficios-',dir=str(path.parent))
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
    root.mkdir(mode=0o700,parents=True,exist_ok=True)
    name=root/'.impe-oficios-modal.lock'
    fd=os.open(str(name),os.O_CREAT|os.O_EXCL|os.O_WRONLY,0o600)
    try:
        os.write(fd,str(os.getpid()).encode('ascii'))
        os.close(fd)
        yield
    finally:
        name.unlink()

def publish(site,backups,manifest):
    verify(site,manifest)
    with lock(backups):
        rows=verify(site,manifest)
        changes=[row for row in rows if row['before']!=row['after']]
        if not changes:
            print('YA INSTALADO: no se modificaron archivos.')
            return
        folder=Path(tempfile.mkdtemp(prefix=datetime.datetime.utcnow().strftime('%Y%m%d-%H%M%S-'),dir=str(backups)))
        os.chmod(str(folder),0o700)
        items=[]
        for row in changes:
            dest=safe(folder,'original/'+row['name'])
            dest.parent.mkdir(mode=0o700,parents=True,exist_ok=True)
            dest.write_bytes(row['before'])
            items.append({'path':row['name'],'before':sha(row['before']),
                          'after':sha(row['after']),'mode':row['mode']})
        (folder/'respaldo.json').write_text(json.dumps(
            {'site':str(site),'files':items},indent=2),encoding='utf-8')
        for row in changes:
            if read(row['path'])!=row['before'] or row['path'].stat().st_mode&0o777!=row['mode']:
                raise RuntimeError('CAMBIO CONCURRENTE: '+row['name']+'; instalación cancelada.')
        applied=[]
        try:
            for row in changes:
                if read(row['path'])!=row['before']:
                    raise RuntimeError('CAMBIO CONCURRENTE: '+row['name'])
                atomic(row['path'],row['after'],row['mode'])
                applied.append(row)
                if read(row['path'])!=row['after']:
                    raise RuntimeError('No fue posible verificar '+row['name'])
        except BaseException:
            for row in reversed(applied):
                if read(row['path'])==row['after']:
                    atomic(row['path'],row['before'],row['mode'])
                else:
                    print('REVISAR MANUALMENTE: '+row['name'],file=sys.stderr)
            raise
        print('APLICADO: selector institucional, Word terminado y fecha de CDMX.')
        print('RESPALDO: '+str(folder))
        print('Sin SQL, correo, grabaciones ni documentos históricos modificados.')

def restore(site,backups,folder):
    source=Path(folder).resolve()
    if backups not in source.parents:
        raise RuntimeError('Respaldo fuera de la ruta privada autorizada.')
    with lock(backups):
        meta=json.loads((source/'respaldo.json').read_text(encoding='utf-8'))
        if meta.get('site')!=str(site):
            raise RuntimeError('El respaldo no corresponde a este CRM.')
        plans=[]
        for item in meta.get('files',[]):
            if item['path'] not in ALLOW:
                raise RuntimeError('Respaldo contiene archivo no autorizado.')
            dest=safe(site,item['path'])
            original=read(safe(source,'original/'+item['path']))
            if sha(original)!=item['before'] or sha(read(dest))!=item['after']:
                raise RuntimeError('CONFLICTO: archivo modificado posteriormente: '+item['path'])
            plans.append((dest,original,item['mode']))
        for dest,original,mode in reversed(plans):
            atomic(dest,original,mode)
        print('RESTAURADO: cinco archivos institucionales, sin SQL.')

def main():
    if sys.version_info<(3,6):
        raise RuntimeError('Python 3.6 o posterior requerido')
    parser=argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--site',required=True)
    parser.add_argument('--backup-root',required=True)
    parser.add_argument('--manifest',default=str(Path(__file__).parent/'oficios_modal_documento_final_manifest.json'))
    group=parser.add_mutually_exclusive_group(required=True)
    group.add_argument('--check',action='store_true')
    group.add_argument('--apply',action='store_true')
    group.add_argument('--restore')
    opt=parser.parse_args()
    site=Path(opt.site).resolve()
    backups=Path(opt.backup_root).resolve()
    if not (site/'index.php').is_file():
        raise RuntimeError('No se encontró el CRM en --site')
    if site==backups or site in backups.parents or backups in site.parents:
        raise RuntimeError('Respaldo debe quedar fuera de public_html.')
    manifest=json.loads(Path(opt.manifest).read_text(encoding='utf-8'))
    if opt.restore:
        restore(site,backups,opt.restore)
    elif opt.apply:
        publish(site,backups,manifest)
    else:
        verify(site,manifest)
        print('CHECK OK: cinco archivos, sintaxis y compatibilidad; sin escribir ni consultar SQL.')

if __name__=='__main__':
    try:
        main()
    except Exception as error:
        print('ERROR: '+str(error),file=sys.stderr)
        sys.exit(1)
