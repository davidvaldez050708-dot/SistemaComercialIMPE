#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Actualización conservadora de la ficha comercial y etiquetas del Kanban.

--check sólo lee 5 archivos del sitio, compara hashes y valida PHP.
--apply exige preflight 2A instalado, respalda y publica 5 archivos exactos.
Nunca toca SQL, asignaciones, expedientes telefónicos, grabaciones o permisos.
Python 3.6+, PHP CLI. Hostinger no necesita Git ni Node.js.
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

REV = '63ad75aff8502915014ccdbd566ebcb563c241e1'
URL = 'https://raw.githubusercontent.com/davidvaldez050708-dot/SistemaComercialIMPE/' + REV
SQL_SHA = '478f3405b956c8801e93af588deec08f5d481054'
PREFLIGHT_SHA = '650fad92ed98a5029d274e3235f24c92b10662fd'

# Hashes Git blob antes (producción fase 2A verificada) y después.
EXISTING = {
    'app/services/EmbudoComercialRepository.php': (
        '26ede5fe8648ef4a898719da46fde59a7d659a23',
        '0f562e8bab496a2049c70e49ab64e0a3d7398c1a'
    ),
    'app/views/embudo_comercial/detalle.php': (
        '660199b4c55734fcab548627eeb5a468f7e0a37b',
        'a97aafad8ad432391b89b67b8c1c2a991266cfcc'
    ),
    'app/views/embudo_comercial/index.php': (
        'e48248b8b97aca2f01c284ad41cd913245a4ffde',
        '22c508bf0a77b28da05853545b683fc6c052df8d'
    ),
    'public/css/embudo_comercial.css': (
        '9ef05c3861bce969592179a617f655fb8056bb6b',
        '4510e51daf4c27f482a4e6cfcf86b24f0ff5af17'
    )
}
NEW = {'app/services/EmbudoComercialPresentacion.php':
       'fde041c40296dbef9405ec492da1366ec6e0716b'}

class Conflicto(RuntimeError):
    pass

def blob(data):
    return hashlib.sha1(('blob %d\0' % len(data)).encode('ascii') + data).hexdigest()

def approved(path,root=None):
    if root is None:
        with urllib.request.urlopen(URL+'/'+path,timeout=35) as response:
            raw=response.read()
    else:
        raw=(root/path).read_bytes()
    expected=EXISTING[path][1] if path in EXISTING else NEW[path]
    if blob(raw)!=expected:
        raise Conflicto('Fuente GitHub no es la revisión aprobada: '+path)
    return raw

def lint_php(path,raw):
    if not path.endswith('.php'):
        return
    binary=shutil.which('php')
    if not binary:
        raise Conflicto('PHP CLI requerido para validar: '+path)
    fd,tmp=tempfile.mkstemp(suffix='.php',prefix='impe-ficha-')
    try:
        with os.fdopen(fd,'wb') as fh:fh.write(raw)
        run=subprocess.run([binary,'-l',tmp],
            stdout=subprocess.PIPE,stderr=subprocess.PIPE,universal_newlines=True,
            timeout=30)
        if run.returncode:
            raise Conflicto('Error de sintaxis '+path+': '+(run.stdout+run.stderr)[-400:])
    finally:
        if os.path.exists(tmp):os.unlink(tmp)

def preparar(site,root=None):
    if not site.is_dir():
        raise Conflicto('Carpeta del sitio no encontrada')
    changes={}
    for path in list(NEW)+list(EXISTING):
        dest=site/path
        if dest.is_symlink() or (dest.exists() and not dest.is_file()):
            raise Conflicto('Archivo no regular o enlace simbólico: '+path)
        old=dest.read_bytes() if dest.is_file() else None
        h=blob(old) if old is not None else None
        if path in EXISTING and h not in EXISTING[path]:
            raise Conflicto('Personalización ajena, sitio intacto: {} sha={}'.format(path,h))
        if path in NEW and h not in (None,NEW[path]):
            raise Conflicto('Archivo nuevo ya personalizado: {} sha={}'.format(path,h))
        new=approved(path,root)
        lint_php(path,new)
        if old==new:
            print('YA APLICADO: '+path)
        else:
            changes[path]=(old,new)
            print('COMPATIBLE PARA PUBLICAR: '+path)
    print('CHECK FICHA: {} por publicar de 5; sin SQL ni cambios en sitio.'.format(len(changes)))
    return changes

def installed_db(site,preflight,sql):
    if not preflight or not sql:
        raise Conflicto('Apply requiere --db-preflight y --sql-file')
    if not preflight.is_file() or blob(preflight.read_bytes())!=PREFLIGHT_SHA:
        raise Conflicto('Integridad de preflight BD incorrecta')
    if not sql.is_file() or blob(sql.read_bytes())!=SQL_SHA:
        raise Conflicto('Integridad de SQL fase2A incorrecta')
    p=subprocess.run(['php',str(preflight),str(site),str(sql),'--installed'],
                     stdout=subprocess.PIPE,stderr=subprocess.PIPE,
                     universal_newlines=True,timeout=45)
    if p.returncode or 'CHECK BD 2A INSTALADA OK' not in p.stdout:
        raise Conflicto('BD fase2A no confirmada: '+(p.stdout+p.stderr)[-650:])
    print('BD FASE 2A: tablas y permisos instalados; distribución desactivada')

def publicar(site,backups,changes):
    if not backups:raise Conflicto('Apply requiere --backup-root privado')
    site=site.resolve()
    backups=backups.resolve()
    if backups==site or site in backups.parents:
        raise Conflicto('Respaldos no pueden quedar dentro de public_html')
    if not changes:
        print('SIN CAMBIOS: ficha y etiquetas ya están actualizadas')
        return
    backups.mkdir(parents=True,exist_ok=True,mode=0o700)
    folder=backups/('ficha-historial-ventas-'+datetime.utcnow().strftime('%Y%m%d-%H%M%S')+'-'+str(os.getpid()))
    folder.mkdir(mode=0o700)
    manifest=[]
    for path,(old,new) in changes.items():
        dest=site/path
        if dest.is_symlink() or (dest.is_file() and dest.read_bytes()!=old):
            raise Conflicto('Archivo cambiado antes de respaldar: '+path)
        if old is not None:
            target=folder/path
            target.parent.mkdir(parents=True,exist_ok=True)
            shutil.copy2(str(dest),str(target))
        manifest.append({'archivo':path,
            'existia':old is not None,
            'antes_sha256':hashlib.sha256(old).hexdigest() if old is not None else None,
            'despues_sha256':hashlib.sha256(new).hexdigest()})
    (folder/'manifest.json').write_text(json.dumps(
        manifest,indent=2,ensure_ascii=False),encoding='utf8')
    done=[]
    try:
        for path,(old,new) in changes.items():
            dest=site/path
            if dest.is_symlink() or (dest.read_bytes() if dest.is_file() else None)!=old:
                raise Conflicto('Archivo modificado durante publicación: '+path)
            if not dest.parent.is_dir():
                raise Conflicto('Directorio destino ausente: '+path)
            fd,tmp=tempfile.mkstemp(prefix='.impe-ficha-',dir=str(dest.parent))
            try:
                with os.fdopen(fd,'wb') as fh:
                    fh.write(new);fh.flush();os.fsync(fh.fileno())
                mode=dest.stat().st_mode & 0o777 if old is not None else 0o644
                os.chmod(tmp,mode)
                os.replace(tmp,str(dest))
                done.append(path)
                lint_php(path,dest.read_bytes())
            finally:
                if os.path.exists(tmp):os.unlink(tmp)
        print('PUBLICADOS: {} archivos ficha e historial'.format(len(done)))
        print('RESPALDO PRIVADO: '+str(folder))
    except Exception:
        for path in reversed(done):
            dest=site/path
            old=changes[path][0]
            if old is None:
                if dest.is_file():dest.unlink()
            else:
                shutil.copy2(str(folder/path),str(dest))
        print('REVERTIDO: archivos restaurados desde '+str(folder),file=sys.stderr)
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
        print('CHECK COMPATIBLE: sólo ficha e interfaz; CERO escrituras.')
        return
    preflight=Path(a.db_preflight).resolve() if a.db_preflight else None
    sql=Path(a.sql_file).resolve() if a.sql_file else None
    installed_db(site,preflight,sql)
    backup=Path(a.backup_root).resolve() if a.backup_root else None
    publicar(site,backup,changes)
    if preparar(site,root):
        raise Conflicto('Post-check no coincide; verificar respaldo')
    print('POST CHECK OK: historial visible, sin Telefonía, SQL ni datos modificados.')

if __name__=='__main__':
    try:main()
    except (OSError,Conflicto,RuntimeError,subprocess.TimeoutExpired) as e:
        print('BLOQUEADO: '+str(e),file=sys.stderr)
        sys.exit(2)
