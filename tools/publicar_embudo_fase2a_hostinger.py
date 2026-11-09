#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Publicador aislado de 10 archivos del Embudo 2A, SIN Telefonía.

check: compara hashes y sintaxis, SIN escrituras del sitio.
apply: exige migración 2A INSTALADA, verifica hash de preflight/SQL,
       crea respaldos fuera de public_html, aplica 10 archivos exactamente
       validados o revierte todo. Nunca toca index.php ni sidebar.php.

Python 3.6+ / PHP 8.2+. Primero ejecutar conciliación de Telefonía por
separado tras aprobar su propia preflight y pruebas de regresión.
"""
import argparse
from datetime import datetime
import hashlib
import json
import os
from pathlib import Path
import shutil
import subprocess
import sys
import tempfile
import urllib.request

REV = '28cfcc5977f38fa9998f3a10209b6d5632126d0a'
URL = 'https://raw.githubusercontent.com/davidvaldez050708-dot/SistemaComercialIMPE/' + REV
SQL_BLOB = '478f3405b956c8801e93af588deec08f5d481054'
PREFLIGHT_BLOB = '650fad92ed98a5029d274e3235f24c92b10662fd'

# Archivo existente: (blob producción antes, blob GitHub aprobado después).
CHANGES = {
    'app/controllers/EmbudoComercialController.php': (
        '01e4565fbb8874ee080714955fafa41d907305fa',
        'fbfc356787390bce06edb8678057c71c95c8a6e0'
    ),
    'app/helpers/AdminRolePolicy.php': (
        '87024131a5d5bb8d2bfe7be9440c232422938d0f',
        '72abfb9e72c7cce457e991b6682efcb10d83ea13'
    ),
    'app/services/EmbudoComercialRepository.php': (
        '45c12542b42a0dbaeee282e7196defef999852e7',
        '26ede5fe8648ef4a898719da46fde59a7d659a23'
    ),
    'app/views/embudo_comercial/detalle.php': (
        'd945736b309ebe546d7cba13b9beabb44a3698ba',
        '660199b4c55734fcab548627eeb5a468f7e0a37b'
    ),
    'app/views/embudo_comercial/index.php': (
        '279f77446d4840fd2425510290c8a5490a77a3e1',
        'e48248b8b97aca2f01c284ad41cd913245a4ffde'
    ),
    'public/css/embudo_comercial.css': (
        'acb7c428900aedcf2f50a564c0886742921927c9',
        '9ef05c3861bce969592179a617f655fb8056bb6b'
    )
}
NEW = {
    'app/services/EmbudoComercialEtapas.php':
        'db47554c68443bcf9f48c58bd25e18a1a0373007',
    'app/services/EmbudoComercialMovimientoService.php':
        '9621a3e2a2b96d0c89ecd597a17c323c4ee09139',
    'app/services/EmbudoComercialResultadoBridge.php':
        '4a60948e006879995b279fab331a1cb822359c2d',
    'public/javascript/embudo_comercial_movimientos.js':
        'bd37e339a401fb1ec06204237011dc93bd53b7d2'
}

class Conflicto(Exception):
    pass

def blob(raw):
    return hashlib.sha1(('blob %d\0' % len(raw)).encode('ascii')+raw).hexdigest()

def source(path, root):
    if root is not None:
        raw=(root/path).read_bytes()
    else:
        with urllib.request.urlopen(URL+'/'+path,timeout=30) as res:
            raw=res.read()
    expected=CHANGES[path][1] if path in CHANGES else NEW[path]
    if blob(raw)!=expected:
        raise Conflicto('GitHub no coincide con integridad aprobada: '+path)
    return raw

def syntax(data,path):
    if not path.endswith(('.php','.js')):
        return
    binary='php' if path.endswith('.php') else 'node'
    runner=shutil.which(binary)
    if runner is None:
        if binary=='node':
            print('AVISO: Node.js ausente. Este JS se verificó en CI; revisar antes de aplicar.')
            return
        raise Conflicto('PHP no disponible para validar '+path)
    fd,tmp=tempfile.mkstemp(suffix='.php' if binary=='php' else '.js',prefix='impe-embudo2a-')
    try:
        with os.fdopen(fd,'wb') as f:
            f.write(data)
        cmd=[runner,'-l',tmp] if binary=='php' else [runner,'--check',tmp]
        result=subprocess.run(cmd,stdout=subprocess.PIPE,stderr=subprocess.PIPE,
            universal_newlines=True)
        if result.returncode:
            raise Conflicto('Sintaxis inválida '+path+': '+(result.stdout+result.stderr)[:350])
    finally:
        if os.path.exists(tmp):os.unlink(tmp)

def preparar(site,root):
    if not site.is_dir():
        raise Conflicto('La ruta del sitio no está disponible')
    pending={}
    for path in list(NEW.keys())+list(CHANGES.keys()):
        target=site/path
        if target.is_symlink():
            raise Conflicto('No se permite enlace simbólico: '+path)
        exists=target.exists()
        if exists and not target.is_file():
            raise Conflicto('Destino no es archivo regular: '+path)
        old=target.read_bytes() if exists else None
        h=blob(old) if old is not None else None
        if path in CHANGES and h not in CHANGES[path]:
            raise Conflicto('Archivo personalizado ajeno a la fase 2A: {} hash {}'.format(path,h))
        if path in NEW and h not in (None,NEW[path]):
            raise Conflicto('Archivo nuevo ya personalizado: {} hash {}'.format(path,h))
        new=source(path,root)
        syntax(new,path)
        if old==new:
            print('YA APLICADO: '+path)
        else:
            pending[path]=(old,new)
            print('COMPATIBLE PARA PUBLICAR: '+path)
    print('CHECK DE ARCHIVOS: {} por publicar de 10; sin SQL ni cambios en sitio.'.format(len(pending)))
    return pending

def installed_db(site,preflight,sql):
    if preflight is None or sql is None:
        raise Conflicto('--apply requiere --db-preflight y --sql-file')
    if not preflight.is_file() or blob(preflight.read_bytes())!=PREFLIGHT_BLOB:
        raise Conflicto('Preflight de BD no es el aprobado de GitHub')
    if not sql.is_file() or blob(sql.read_bytes())!=SQL_BLOB:
        raise Conflicto('Archivo SQL de fase 2A incorrecto')
    result=subprocess.run(
        ['php',str(preflight),str(site),str(sql),'--installed'],
        stdout=subprocess.PIPE,stderr=subprocess.PIPE,universal_newlines=True
    )
    if result.returncode:
        raise Conflicto('Fase 2A NO INSTALADA: '+(result.stdout+result.stderr)[-600:])
    if 'CHECK BD 2A INSTALADA OK' not in result.stdout:
        raise Conflicto('Preflight BD no confirmó instalación')
    print('BD 2A LISTA: tablas y permisos instalados, motores apagados')

def publicar(site,backup_root,changes):
    if backup_root is None:
        raise Conflicto('--apply requiere --backup-root fuera del sitio')
    site=site.resolve()
    backup_root=backup_root.resolve()
    if backup_root==site or site in backup_root.parents:
        raise Conflicto('Respaldo no puede estar bajo public_html')
    if not changes:
        print('SIN CAMBIOS: los 10 archivos ya están publicados')
        return
    backup_root.mkdir(parents=True,exist_ok=True)
    directory=backup_root/('embudo-fase2a-'+datetime.utcnow().strftime('%Y%m%d-%H%M%S')+'-'+str(os.getpid()))
    directory.mkdir(mode=0o700)
    manifest=[]
    for path,(old,new) in changes.items():
        target=site/path
        if target.is_file() and old is not None:
            b=directory/path
            b.parent.mkdir(parents=True,exist_ok=True)
            shutil.copy2(str(target),str(b))
        manifest.append({
            'archivo':path,'existia':old is not None,
            'antes_sha256':hashlib.sha256(old).hexdigest() if old is not None else None,
            'despues_sha256':hashlib.sha256(new).hexdigest()
        })
    (directory/'manifest.json').write_text(
        json.dumps(manifest,indent=2,ensure_ascii=False),encoding='utf-8'
    )
    done=[]
    try:
        for path,(old,new) in changes.items():
            target=site/path
            before=target.read_bytes() if target.is_file() else None
            if before != old:
                raise Conflicto('Archivo cambiado durante publicación: '+path)
            # Los directorios de los cuatro archivos nuevos ya existen en fase 1.
            if not target.parent.is_dir():
                raise Conflicto('Carpeta destino ausente: '+path)
            fd,tmp=tempfile.mkstemp(prefix='.impe-fase2a-',dir=str(target.parent))
            try:
                with os.fdopen(fd,'wb') as f:
                    f.write(new);f.flush();os.fsync(f.fileno())
                os.chmod(tmp,target.stat().st_mode & 0o777 if old is not None else 0o644)
                os.replace(tmp,str(target))
                done.append(path)
                syntax(target.read_bytes(),path)
            finally:
                if os.path.exists(tmp):os.unlink(tmp)
        print('PUBLICADOS: {} archivos fase 2A'.format(len(done)))
        print('RESPALDO PRIVADO: '+str(directory))
    except Exception:
        for path in reversed(done):
            old=changes[path][0]
            target=site/path
            if old is None:
                if target.is_file():target.unlink()
            else:
                shutil.copy2(str(directory/path),str(target))
        print('REVERTIDO: restaurados archivos modificados; respaldos: '+str(directory),file=sys.stderr)
        raise

def main():
    pa=argparse.ArgumentParser()
    pa.add_argument('--site',required=True)
    pa.add_argument('--source-root')
    pa.add_argument('--backup-root')
    pa.add_argument('--db-preflight')
    pa.add_argument('--sql-file')
    g=pa.add_mutually_exclusive_group(required=True)
    g.add_argument('--check',action='store_true')
    g.add_argument('--apply',action='store_true')
    a=pa.parse_args()
    site=Path(a.site).resolve()
    root=Path(a.source_root).resolve() if a.source_root else None
    changes=preparar(site,root)
    if a.check:
        print('CHECK COMPATIBLE: solo 10 archivos del embudo, sin Telefonía ni escrituras.')
        return
    installed_db(site,Path(a.db_preflight).resolve() if a.db_preflight else None,
                 Path(a.sql_file).resolve() if a.sql_file else None)
    publicar(site,Path(a.backup_root).resolve() if a.backup_root else None,changes)
    if preparar(site,root):
        raise Conflicto('Post-check inesperado; revisar el respaldo')
    print('POST CHECK OK: embudo 2A publicado. Telefonía personalizada intacta.')

if __name__=='__main__':
    try:main()
    except (OSError,Conflicto,RuntimeError) as e:
        print('BLOQUEADO: '+str(e),file=sys.stderr)
        sys.exit(2)
