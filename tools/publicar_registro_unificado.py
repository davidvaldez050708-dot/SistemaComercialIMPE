#!/usr/bin/env python3
"""Unifica los datos de registro del analista y mantiene separado al contacto referido."""
import argparse
import base64
import hashlib
import json
import os
from pathlib import Path
import shutil
import subprocess
import tempfile

FILES=('public/javascript/telefonia_atencion.js', 'public/javascript/seguimiento_interacciones.js', 'public/javascript/seguimiento_entrantes_analista.js', 'app/views/telefonia/resultado_analista_form.php', 'app/services/DatosContactoLlamadaService.php')

def sha(data): return hashlib.sha256(data).hexdigest()

def safe(root,name):
    p=(root/name).resolve()
    if root not in p.parents:raise RuntimeError('Ruta fuera del directorio autorizado')
    return p

def candidate(old,row):
    if row['new_file']:
        new=base64.b64decode(row['content'])
        if sha(new)!=row['after_sha']:raise RuntimeError('Paquete alterado: '+row['path'])
        if old is not None and old.replace(b'\r\n',b'\n')!=new:raise RuntimeError('Archivo nuevo ya personalizado: '+row['path'])
        return old if old is not None else new
    if old is None:raise RuntimeError('Falta archivo instalado: '+row['path'])
    text=old.replace(b'\r\n',b'\n').decode('utf-8')
    for hunk in row['hunks']:
        choices=[hunk]+hunk.get('variants',[])
        applied={c['after'] for c in choices if text.count(c['after'])==1}
        if len(applied)==1:continue
        if len(applied)>1:raise RuntimeError('Variantes ambiguas: '+row['path'])
        matches=[c for c in choices if text.count(c['before'])==1]
        if len(matches)!=1:raise RuntimeError('Bloque diferente: '+row['path']+'. Comparar antes de publicar; sitio intacto.')
        text=text.replace(matches[0]['before'],matches[0]['after'],1)
    data=text.encode('utf-8')
    return data.replace(b'\n',b'\r\n') if b'\r\n' in old else data

def lint(data,name):
    if name.endswith('.css'):return
    exe=shutil.which('php' if name.endswith('.php') else 'node')
    if not exe:
        if name.endswith('.php'):raise RuntimeError('PHP CLI es obligatorio')
        return
    with tempfile.TemporaryDirectory(prefix='registro-unificado-lint-') as tmp:
        p=Path(tmp)/Path(name).name;p.write_bytes(data)
        r=subprocess.run([exe,'-l' if name.endswith('.php') else '--check',str(p)],stdout=subprocess.PIPE,stderr=subprocess.PIPE)
        if r.returncode:raise RuntimeError('Sintaxis no válida: '+name)

def write(p,data,mode):
    fd,tmp=tempfile.mkstemp(prefix='.registro-unificado-',dir=str(p.parent))
    try:
        with os.fdopen(fd,'wb') as stream:stream.write(data);stream.flush();os.fsync(stream.fileno())
        os.chmod(tmp,mode);os.replace(tmp,str(p))
    finally:
        if os.path.exists(tmp):os.unlink(tmp)

def main():
    parser=argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--site',required=True);parser.add_argument('--backup-root',required=True)
    mode=parser.add_mutually_exclusive_group(required=True)
    mode.add_argument('--check',action='store_true');mode.add_argument('--apply',action='store_true');mode.add_argument('--restore')
    args=parser.parse_args();site=Path(args.site).resolve();backups=Path(args.backup_root).resolve()
    if not (site/'index.php').is_file():raise RuntimeError('No es la raíz del CRM')
    if backups==site or site in backups.parents:raise RuntimeError('El respaldo debe estar fuera del sitio público')
    spec=json.loads(Path(__file__).with_name('registro_unificado_release.json').read_text(encoding='utf-8'))
    if tuple(row['path'] for row in spec['files'])!=FILES:raise RuntimeError('Paquete inválido')
    plan=[]
    if args.restore:
        backup=Path(args.restore).resolve()
        if backups not in backup.parents:raise RuntimeError('Respaldo no autorizado')
        record=json.loads((backup/'manifest.json').read_text(encoding='utf-8'))
        names=[x['path'] for x in record['files']]
        if record['release']!=spec['release'] or len(names)!=len(set(names)) or not set(names).issubset(FILES):raise RuntimeError('Respaldo inválido')
        for row in reversed(record['files']):
            p=safe(site,row['path']);old=p.read_bytes() if p.exists() else None
            new=None if row['old_sha'] is None else safe(backup,row['path']).read_bytes()
            if new is not None and sha(new)!=row['old_sha']:raise RuntimeError('Respaldo alterado')
            if old==new:continue
            if old is None or sha(old)!=row['new_sha']:raise RuntimeError('Cambios posteriores: '+row['path'])
            plan.append((row['path'],p,old,new,row['mode']))
    else:
        for row in spec['files']:
            p=safe(site,row['path']);old=p.read_bytes() if p.exists() else None;new=candidate(old,row);lint(new,row['path'])
            if old!=new:plan.append((row['path'],p,old,new,(p.stat().st_mode&0o777) if p.exists() else 0o644))
        if args.check:print('COMPROBACION_OK: '+str(len(plan))+' archivos; sitio intacto.');return
        if not plan:print('SIN_CAMBIOS: paquete ya aplicado.');return
        backups.mkdir(parents=True,exist_ok=True,mode=0o700)
        backup=Path(tempfile.mkdtemp(prefix='registro-unificado-',dir=str(backups)))
        rows=[]
        for name,p,old,new,mode in plan:
            if old is not None:
                saved=safe(backup,name);saved.parent.mkdir(parents=True,exist_ok=True);saved.write_bytes(old);os.chmod(saved,0o600)
            rows.append(dict(path=name,old_sha=None if old is None else sha(old),new_sha=sha(new),mode=mode))
        (backup/'manifest.json').write_text(json.dumps(dict(release=spec['release'],files=rows)),encoding='utf-8')
        print('RESPALDO: '+str(backup))
    written=[]
    try:
        for name,p,old,new,mode in plan:
            if (p.read_bytes() if p.exists() else None)!=old:raise RuntimeError('Cambio concurrente: '+name)
            if new is None:p.unlink()
            else:write(p,new,mode)
            written.append((p,old,mode))
        for name,p,old,new,mode in plan:
            if (p.read_bytes() if p.exists() else None)!=new:raise RuntimeError('Verificación posterior falló: '+name)
    except BaseException:
        for p,old,mode in reversed(written):
            if old is None:p.unlink()
            else:write(p,old,mode)
        raise
    print(('RESTAURACION_OK' if args.restore else 'PUBLICACION_OK')+': '+str(len(plan))+' archivos. Registro de llamada unificado; una persona en la atencion normal y otro contacto solo cuando es referido. No se enviaron mensajes ni se iniciaron llamadas.')

if __name__=='__main__':
    try:main()
    except (OSError,RuntimeError,ValueError,KeyError) as e:raise SystemExit('BLOQUEADO: '+str(e))
