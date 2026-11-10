#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Publicador V1 por lista cerrada: cierre API verificado, contexto PBX y caché del embudo; preserva el formulario V4.
No toca centralita, SDK, grabaciones, datos, MySQL ni el formulario de las asesoras.
PRECHECK admite solo los hashes exactos de la versión productiva auditada.
--check es completamente de solo lectura en el sitio.
--apply exige confirmación, SHA exactos, respaldo externo y revisión previa;
--rollback exige el manifiesto íntegro y las versiones desplegadas exactas.
Compatible con Python 3.6 en Hostinger; no usa capture_output.
"""
from __future__ import print_function
import argparse
import hashlib
import json
import os
from pathlib import Path
import shutil
import stat
import subprocess
import tempfile
from datetime import datetime

FILES = {
    'public/javascript/embudo_comercial_movimientos.js': (
        'bd37e339a401fb1ec06204237011dc93bd53b7d2',
        '05ee0263bb677cdc0be5c58e633d23e99e585e81'),
    'public/javascript/telefonia_ventas_resultados.js': (
        'cc28197921d366001e4b15f0edd1bdc1e0cc626d',
        '451fbb4014b49868fffd56fd80eb4ebda2a16218'),
    'app/services/TelefoniaResultadoVentasService.php': (
        '83c072703cc7023bd6f0b313cef4ee4e2f0e7d22',
        '1d4472ba73e7de4097ef2f592104160b254d7f8f'),
    'app/services/EmbudoComercialResultadoBridge.php': (
        'de7371eb9077948e503939f59e60a84137e47052',
        '6d990a8b12d4856c83e61dc67ad87b34e7767748'),
    'app/views/embudo_comercial/index.php': (
        '0b0c361e1eef700e00ff18cea18d479053441003',
        '8c12c1af24c73badcb68ff372ea04e1ba0622c7c'),
    'app/views/embudo_comercial/detalle.php': (
        'cc6f4774817ffbe8fe7a3b4b971549788526c0e0',
        'e93e64c10088bb6351af3f0412b0d12cfee736a9')
}
GUARDS = {
    'app/views/telefonia/resultado_ventas_form.php':
        '483441e232c69d5ee62019994162820838a9b717',
    'app/services/TelefoniaVentasCierreApiVerificado.php':
        '21ca739a0ec7723cac07399bb1c3840638137ce9',
    'app/services/EmbudoComercialEtapas.php':
        'a2cc9493b3d5b980096c768b15fecbfa9af710a8'
}
CONFIRM = 'PUBLICAR_CIERRE_API_EMBUDO_V1'

class Blocked(Exception): pass

def blob(raw):
    return hashlib.sha1(('blob %d\0' % len(raw)).encode('ascii')+raw).hexdigest()

def regular(path):
    if path.is_symlink() or not path.is_file():
        raise Blocked('Archivo ausente, no regular o simbólico: '+str(path))

def site_path(root, rel):
    path=root
    for part in rel.split('/'):
        path=path/part
        if path.is_symlink():
            raise Blocked('Enlace simbólico bloqueado: '+str(path))
    regular(path)
    return path

def root_site(value):
    root=Path(value).resolve(strict=True)
    if not root.is_dir() or root.name!='public_html':
        raise Blocked('--site debe apuntar a public_html existente')
    return root

def source_path(source_dir, rel):
    source=source_dir
    for part in rel.split('/'):
        source=source/part
        if source.is_symlink():raise Blocked('Fuente simbólica bloqueada: '+str(source))
    regular(source)
    return source

def valid_php(path):
    p=subprocess.run(['php','-l',str(path)],stdout=subprocess.PIPE,
                     stderr=subprocess.PIPE,universal_newlines=True)
    if p.returncode:
        raise Blocked('PHP inválido: '+str(path)+' '+p.stderr[:400])

def scan(site, sources=None):
    # Servicios y catálogo de telefonía críticos: hashes protegidos y solo lectura.
    for rel,expected in GUARDS.items():
        actual=blob(site_path(site,rel).read_bytes())
        if actual!=expected:
            raise Blocked('SERVICIO CRÍTICO PERSONALIZADO: '+rel+' '+actual+'. Sitio intacto.')
        print('PROTEGIDO SIN MODIFICAR: '+rel+' '+actual)
    seen={}
    for rel,(old,new) in FILES.items():
        target=site_path(site,rel)
        current=blob(target.read_bytes())
        if current not in (old,new):
            raise Blocked('CONFLICTO DE PERSONALIZACIÓN: '+rel+' '+current+'. No se modifica nada.')
        if sources is not None:
            src=source_path(sources,rel)
            if blob(src.read_bytes())!=new:
                raise Blocked('Fuente GitHub no coincide con hash aprobado: '+rel)
            if rel.endswith('.php'): valid_php(src)
        seen[rel]=current
        print(('YA PUBLICADO' if current==new else 'COMPATIBLE')+': '+rel+' '+current)
    return seen

def atomic(path, raw):
    mode=stat.S_IMODE(path.stat().st_mode)
    fd,tmp=tempfile.mkstemp(prefix='.impe-ventas-v4-',dir=str(path.parent))
    try:
        with os.fdopen(fd,'wb') as out:
            out.write(raw);out.flush();os.fsync(out.fileno())
        os.chmod(tmp,mode)
        os.replace(tmp,str(path))
    finally:
        if os.path.exists(tmp):os.unlink(tmp)

def private_root(site,root):
    root=Path(root).resolve()
    if root==site or site in root.parents:
        raise Blocked('Backups deben estar fuera de public_html')
    if root.exists() and not root.is_dir():
        raise Blocked('Backup root no es directorio')
    return root

def publish(site,sources,backup_root,confirmation):
    if confirmation!=CONFIRM:
        raise Blocked('No autorizado; se requiere token explícito para publicación con asesores activos')
    observed=scan(site,sources)
    changed=[r for r in FILES if observed[r]==FILES[r][0]]
    if not changed:
        print('SIN CAMBIOS: todas las versiones ya coinciden.')
        return
    if not backup_root:
        raise Blocked('Se requiere --backup-root fuera del sitio')
    base=private_root(site,backup_root)
    base.mkdir(parents=True,exist_ok=True)
    folder=base/('ventas-cierre-api-'+datetime.utcnow().strftime('%Y%m%d-%H%M%S')+'-'+str(os.getpid()))
    folder.mkdir(mode=0o700)
    original={}
    for rel in changed:
        target=site_path(site,rel);raw=target.read_bytes()
        if blob(raw)!=FILES[rel][0]:
            raise Blocked('Cambió el sitio durante respaldo: '+rel)
        original[rel]=raw
        backup=folder/rel
        backup.parent.mkdir(parents=True,exist_ok=True)
        backup.write_bytes(raw);os.chmod(str(backup),0o600)
        if blob(backup.read_bytes())!=FILES[rel][0]:
            raise Blocked('Copia de respaldo inválida: '+rel)
    manifest={'version':1,'archivo_por_archivo':{
        rel:{'antes':FILES[rel][0],'despues':FILES[rel][1]} for rel in changed
    },'sin_sql':True,'fecha_utc':datetime.utcnow().strftime('%Y-%m-%dT%H:%M:%SZ')}
    mf=folder/'manifest.json'
    mf.write_text(json.dumps(manifest,indent=2,ensure_ascii=False),encoding='utf-8')
    os.chmod(str(mf),0o600)
    try:
        for rel in changed:
            target=site_path(site,rel)
            if blob(target.read_bytes())!=FILES[rel][0]:
                raise Blocked('Archivo cambió antes de reemplazar: '+rel)
            raw=source_path(sources,rel).read_bytes()
            if blob(raw)!=FILES[rel][1]:
                raise Blocked('Archivo fuente cambió: '+rel)
            atomic(target,raw)
            if blob(target.read_bytes())!=FILES[rel][1]:
                raise Blocked('Publicación no íntegra: '+rel)
            if rel.endswith('.php'):valid_php(target)
            print('PUBLICADO: '+rel)
    except Exception:
        for rel in reversed(changed):
            target=site_path(site,rel)
            if blob(target.read_bytes())==FILES[rel][1]:
                atomic(target,original[rel])
            elif blob(target.read_bytes())!=FILES[rel][0]:
                print('ALERTA: archivo con cambios externos; NO SOBRESCRIBIR: '+rel)
        raise
    print('RESPALDO PRIVADO: '+str(folder))
    print('POST-CHECK OK: sin SQL, PBX, grabaciones, sesiones ni teléfonos modificados.')

def rollback(site,folder,confirmation):
    if confirmation!=CONFIRM:raise Blocked('Rollback requiere confirmación explícita')
    folder=Path(folder).resolve(strict=True)
    if site==folder or site in folder.parents:raise Blocked('Respaldo no privado')
    mf=folder/'manifest.json';regular(mf)
    m=json.loads(mf.read_text(encoding='utf-8'))
    if m.get('version')!=1 or m.get('sin_sql') is not True:
        raise Blocked('Manifiesto inválido')
    listing=m.get('archivo_por_archivo')
    if not isinstance(listing,dict) or not listing:
        raise Blocked('Manifiesto vacío')
    data={}
    for rel,item in listing.items():
        if rel not in FILES or item!={'antes':FILES[rel][0],'despues':FILES[rel][1]}:
            raise Blocked('Manifiesto adulterado: '+rel)
        raw=source_path(folder,rel).read_bytes()
        if blob(raw)!=FILES[rel][0]:raise Blocked('Backup adulterado: '+rel)
        target=site_path(site,rel)
        if blob(target.read_bytes()) not in FILES[rel]:
            raise Blocked('Rollback bloqueado por personalización: '+rel)
        data[rel]=raw
    for rel,raw in data.items():
        target=site_path(site,rel)
        if blob(target.read_bytes())==FILES[rel][1]:
            atomic(target,raw)
            print('RESTAURADO: '+rel)
        else:
            print('SIN CAMBIOS: '+rel)
    print('ROLLBACK OK; no se alteraron datos.')

def main():
    parser=argparse.ArgumentParser()
    parser.add_argument('--site',required=True)
    parser.add_argument('--source-dir')
    parser.add_argument('--backup-root')
    parser.add_argument('--backup-folder')
    parser.add_argument('--confirm')
    action=parser.add_mutually_exclusive_group(required=True)
    action.add_argument('--check',action='store_true')
    action.add_argument('--apply',action='store_true')
    action.add_argument('--rollback',action='store_true')
    args=parser.parse_args()
    site=root_site(args.site)
    if args.rollback:
        if not args.backup_folder:raise Blocked('Falta --backup-folder')
        rollback(site,args.backup_folder,args.confirm)
        return
    if not args.source_dir:raise Blocked('Falta --source-dir')
    sources=Path(args.source_dir).resolve(strict=True)
    if sources==site or site in sources.parents:raise Blocked('Fuente no puede ser sitio productivo')
    if args.check:
        scan(site,sources)
        print('CHECK OK: inspección solo lectura, seis archivos y tres guardias sin conflictos.')
    else:publish(site,sources,args.backup_root,args.confirm)
if __name__=='__main__':
    try:main()
    except (Blocked,OSError,ValueError,subprocess.SubprocessError) as e:
        raise SystemExit('BLOQUEADO: '+str(e))
