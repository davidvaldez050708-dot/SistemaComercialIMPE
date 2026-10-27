#!/usr/bin/env python3
"""Publicación selectiva: hashes exactos, backup privado, sin SQL ni secretos."""
import argparse,base64,gzip,hashlib,json,os,shutil,stat,subprocess,sys,tempfile,time
from pathlib import Path

def fail(message):raise RuntimeError(message)
def sha(data):return hashlib.sha256(data).hexdigest()
def unpack(value,expected):
    data=gzip.decompress(base64.b64decode(value))
    if sha(data)!=expected:fail('Integridad del paquete inválida')
    return data
def safe(root,name):
    rel=Path(name)
    if rel.is_absolute() or '..' in rel.parts:fail('Ruta inválida')
    p=root/rel
    for q in [p]+list(p.parents):
        if q==root:break
        if q.is_symlink():fail('Enlace simbólico no admitido: '+name)
    if root not in p.resolve().parents:fail('Ruta fuera del sitio')
    return p
def atomic(p,data,mode=0o644):
    p.parent.mkdir(parents=True,exist_ok=True)
    fd,tmp=tempfile.mkstemp(prefix='.impe-supervision-',dir=str(p.parent))
    try:
        with os.fdopen(fd,'wb') as f:f.write(data);f.flush();os.fsync(f.fileno())
        os.chmod(tmp,mode);os.replace(tmp,str(p))
    finally:
        if os.path.exists(tmp):os.unlink(tmp)
def lint(name,data):
    if name.endswith('.css'):return # CSS verificado con el navegador real.
    executable='php' if name.endswith('.php') else 'node'
    exe=shutil.which(executable)
    if not exe:
        if executable=='php':fail('PHP es necesario para validar el paquete')
        return
    fd,tmp=tempfile.mkstemp(suffix=Path(name).suffix)
    try:
        with os.fdopen(fd,'wb') as f:f.write(data)
        r=subprocess.run([exe,'-l' if executable=='php' else '--check',tmp],stdout=subprocess.PIPE,stderr=subprocess.PIPE)
        if r.returncode:fail('Error de sintaxis: '+name)
    finally:os.unlink(tmp)
def main():
    parser=argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--site',required=True)
    parser.add_argument('--backup-root',required=True)
    parser.add_argument('--manifest',default=str(Path(__file__).with_name('supervision_llamadas_release.json')))
    mode=parser.add_mutually_exclusive_group(required=True)
    mode.add_argument('--check',action='store_true');mode.add_argument('--apply',action='store_true');mode.add_argument('--restore')
    args=parser.parse_args();root=Path(args.site).resolve();backups=Path(args.backup_root).resolve()
    if not root.is_dir() or not (root/'index.php').is_file():fail('No es la raíz del CRM')
    if backups==root or root in backups.parents:fail('El respaldo debe quedar fuera de public_html')
    spec=json.loads(Path(args.manifest).read_text(encoding='utf8'))
    paths=[row['path'] for row in spec['files']]
    if len(paths)!=9 or set(paths)!={'app/services/SupervisionLlamadasPermisosService.php', 'public/javascript/supervision_llamadas.js', 'public/css/supervision_llamadas.css', 'app/views/telefonia/supervision_llamadas.php', 'app/views/layout/sidebar.php', 'app/services/SupervisionLlamadasRepository.php', 'app/controllers/ReporteController.php', 'app/services/SupervisionLlamadasAudioService.php', 'app/controllers/SupervisionLlamadasController.php'}:fail('Manifiesto inválido')
    if not args.restore:
        for dependency in spec.get('dependencies',[]):
            p=safe(root,dependency['path'])
            if not p.is_file() or sha(p.read_bytes().replace(b'\r\n',b'\n'))!=dependency['sha']:
                fail('DEPENDENCIA DIFERENTE: '+dependency['path']+'. Sitio intacto; comparar antes de publicar.')
    # Verificar todos los blobs incluso para check/restore.
    for row in spec['files']:
        for variant in row['variants']:
            unpack(variant['after'],variant['after_sha'])
            if variant['before'] is not None:unpack(variant['before'],variant['before_sha'])
    if args.restore:
        backup=Path(args.restore).resolve()
        if backups not in backup.parents or not backup.is_dir():fail('Ruta de respaldo no autorizada')
        record=json.loads((backup/'manifest.json').read_text(encoding='utf8'))
        if record['release']!=sha(Path(args.manifest).read_bytes()) or (not record['files'] or not set(x['path'] for x in record['files']).issubset(set(paths)) or len(set(x['path'] for x in record['files']))!=len(record['files'])):fail('Respaldo de otra entrega')
        plan=[]
        for row in reversed(record['files']):
            p=safe(root,row['path']);current=p.read_bytes() if p.exists() else None
            old=None
            if row['old_sha'] is not None:
                old=safe(backup,row['path']).read_bytes()
                if sha(old)!=row['old_sha']:fail('Respaldo alterado')
            if current==old:continue # También restaura una aplicación interrumpida.
            if sha(current or b'')!=row['new_sha']:fail('Cambios posteriores; restauración bloqueada: '+row['path'])
            plan.append((p,current,old,row['mode']))
        written=[]
        try:
            for p,current,old,mode in plan:
                if p.read_bytes()!=current:fail('Cambio concurrente')
                if old is None:p.unlink()
                else:atomic(p,old,mode)
                written.append((p,current,mode))
        except Exception:
            for p,current,mode in reversed(written):atomic(p,current,mode)
            raise
        print('RESTAURADO: archivos anteriores exactos; sin SQL.');return
    plan=[]
    for row in spec['files']:
        p=safe(root,row['path']);original=p.read_bytes() if p.exists() else None
        normalized=original.replace(b'\r\n',b'\n') if original is not None else None
        variant=next((v for v in row['variants'] if normalized is not None and sha(normalized)==v['after_sha']),None)
        if variant is not None:
            print('ACTUALIZADO: '+row['path']);continue
        variant=next((v for v in row['variants'] if (original is None and v['before'] is None) or
            (normalized is not None and sha(normalized)==v['before_sha'])),None)
        if variant is None:fail('CONFLICTO: variante desconocida; sitio intacto: '+row['path'])
        candidate=unpack(variant['after'],variant['after_sha'])
        if original is not None and b'\r\n' in original:
            if original.count(b'\r\n')!=original.count(b'\n'):fail('Saltos mixtos: '+row['path'])
            candidate=candidate.replace(b'\n',b'\r\n')
        lint(row['path'],candidate)
        mode=stat.S_IMODE(p.stat().st_mode) if p.exists() else 0o644
        plan.append((row['path'],p,original,candidate,mode));print('COMPATIBLE: '+row['path']+' '+(sha(original) if original is not None else 'nuevo')+' -> '+sha(candidate))
    if args.check:
        print('CHECK OK: sin escrituras. Supervisión de llamadas compatible.');return
    if not plan:print('SIN CAMBIOS: paquete ya instalado.');return
    backups.mkdir(parents=True,exist_ok=True);os.chmod(str(backups),0o700)
    backup=Path(tempfile.mkdtemp(prefix=time.strftime('%Y%m%d-%H%M%S-'),dir=str(backups)));os.chmod(str(backup),0o700)
    rows=[]
    for name,p,old,new,mode in plan:
        if old is not None:atomic(safe(backup,name),old,0o600)
        rows.append({'path':name,'old_sha':sha(old) if old is not None else None,'new_sha':sha(new),'mode':mode})
    # Restaurar exclusivamente los archivos modificados por esta aplicación.
    record={'release':sha(Path(args.manifest).read_bytes()),'files':rows}
    atomic(backup/'manifest.json',(json.dumps(record,indent=2)+'\n').encode(),0o600)
    written=[]
    try:
        # Revalidar todo antes de la primera escritura.
        for name,p,old,new,mode in plan:
            if (p.read_bytes() if p.exists() else None)!=old:fail('Cambio concurrente: '+name)
        for name,p,old,new,mode in plan:
            if (p.read_bytes() if p.exists() else None)!=old:fail('Cambio concurrente: '+name)
            atomic(p,new,mode);written.append((p,old,mode))
    except Exception:
        for p,old,mode in reversed(written):
            if old is None:p.unlink()
            else:atomic(p,old,mode)
        raise
    print('APLICADO: supervisión de llamadas. RESPALDO: '+str(backup))
if __name__=='__main__':
    try:main()
    except Exception as error:print('ERROR: '+str(error),file=sys.stderr);sys.exit(1)
