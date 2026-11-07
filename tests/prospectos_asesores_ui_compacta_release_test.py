#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Verifica el paquete 1A.3 en una copia desechable de la instalación 1A.2."""
import base64
import gzip
import hashlib
import json
import pathlib
import shutil
import subprocess
import sys
import tempfile

ROOT=pathlib.Path(__file__).resolve().parents[1]
PACKAGE=json.loads((ROOT/'tools/prospectos_asesores_ui_compacta_release.json').read_text(encoding='utf-8'))
PREVIOUS=json.loads((ROOT/'tools/prospectos_asesores_multiprograma_release.json').read_text(encoding='utf-8'))
FIRST=json.loads((ROOT/'tools/prospectos_asesores_1a_release.json').read_text(encoding='utf-8'))
CAMPAIGN=json.loads((ROOT/'tools/prospectos_campanias_release.json').read_text(encoding='utf-8'))
count=0

def assert_ok(ok, name):
    global count
    if not ok:raise AssertionError('FALLÓ: '+name)
    count+=1

def sha(data):
    return hashlib.sha256(data).hexdigest()

def write(path,data):
    path.parent.mkdir(parents=True,exist_ok=True)
    path.write_bytes(data)

def execute(script,site,flags,success=True):
    task=subprocess.run([sys.executable,str(script),'--site',str(site)]+flags,
         stdout=subprocess.PIPE,stderr=subprocess.STDOUT,universal_newlines=True)
    if (task.returncode==0)!=success:
        raise AssertionError('Comando inesperado '+repr(flags)+': '+task.stdout)
    return task.stdout

assert_ok(PACKAGE['package']=='impe_prospectos_asesores_ui_compacta','identidad del release')
assert_ok({i['path'] for i in PACKAGE['files']}=={
    'app/views/prospectos/partials/configuracion_asesores.php',
    'public/css/prospectos_asesores.css'},'solo cambia vista y estilos')
old_files={f['path']:f for f in PREVIOUS['files']}
first_files={f['path']:f for f in FIRST['files']}
campaign_files={f['path']:f for f in CAMPAIGN['files']}
assert_ok(len(PACKAGE['dependencies'])==6,'seis dependencias inmutables')

with tempfile.TemporaryDirectory(prefix='impe-ui-compacta-release-') as tmp:
    root=pathlib.Path(tmp)
    site=root/'public_html';site.mkdir()
    tools=root/'tools';tools.mkdir()
    backups=root/'backups'
    publisher=tools/'publicar_prospectos_asesores_ui_compacta.py'
    shutil.copy2(str(ROOT/'tools/publicar_prospectos_asesores_ui_compacta.py'),str(publisher))
    release=json.loads(json.dumps(PACKAGE))

    sources={
      'app/controllers/ProspectoController.php':base64.b64decode(first_files['app/controllers/ProspectoController.php']['payload_b64']),
      'public/css/prospectos_central.css':gzip.decompress(base64.b64decode(campaign_files['public/css/prospectos_central.css']['target'])),
      'app/models/RolModel.php':b'RolModel personalizado (fixture, nunca publicar)',
    }
    for p in ('app/services/ProspectoAsesorConfiguracionService.php',
              'app/views/prospectos/central.php',
              'public/javascript/prospectos_asesores.js'):
        sources[p]=base64.b64decode(old_files[p]['payload_b64'])

    for dep in release['dependencies']:
        data=sources[dep['path']]
        if dep['path']=='app/models/RolModel.php':
            dep['sha256']=sha(data)
        else:
            assert_ok(sha(data)==dep['sha256'],'dependencia 1A.2 '+dep['path'])
        write(site/dep['path'],data)

    originals={}
    for f in release['files']:
        before=base64.b64decode(old_files[f['path']]['payload_b64'])
        assert_ok(sha(before)==f['base_sha256'],'base exacta '+f['path'])
        originals[f['path']]=before
        write(site/f['path'],before)

    (tools/'prospectos_asesores_ui_compacta_release.json').write_text(json.dumps(release),encoding='utf-8')
    assert_ok('CHECK COMPATIBLE' in execute(publisher,site,['--check']),'check sin escrituras')
    assert_ok(not backups.exists(),'check no crea respaldo')
    assert_ok('PUBLICADO: 2 archivos' in execute(publisher,site,['--apply','--backup-root',str(backups)]),'publicar dos archivos')
    for f in release['files']:
        assert_ok(sha((site/f['path']).read_bytes())==f['sha256'],'hash publicado '+f['path'])
    assert_ok('ALREADY APPLIED' in execute(publisher,site,['--apply','--backup-root',str(backups)]),'apply idempotente')
    backup_dirs=list(backups.iterdir())
    assert_ok(len(backup_dirs)==1,'un respaldo aislado')
    assert_ok('RESTORE COMPLETO' in execute(publisher,site,['--restore',str(backup_dirs[0])]),'restore')
    for p,data in originals.items():
        assert_ok((site/p).read_bytes()==data,'restauración exacta '+p)
    assert_ok(all((site/p).read_bytes()==data for p,data in sources.items()),'dependencias preservadas')
    path=site/'app/views/prospectos/partials/configuracion_asesores.php'
    write(path,b'Modificado manualmente')
    assert_ok('Conflicto:' in execute(publisher,site,['--apply','--backup-root',str(backups)],False),'conflicto de vista')
    write(path,originals['app/views/prospectos/partials/configuracion_asesores.php'])
    dep_path=site/'app/models/RolModel.php'
    write(dep_path,b'Modificado manualmente')
    assert_ok('Dependencia diferente:' in execute(publisher,site,['--check'],False),'conflicto en RolModel')
    assert_ok(len(list(backups.iterdir()))==1,'sin respaldos adicionales al fallar')

print('OK: %d verificaciones de compatibilidad, publicación y restauración 1A.3.'%count)
