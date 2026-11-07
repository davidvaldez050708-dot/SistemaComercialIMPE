#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Test 1A.2 sealed publisher against a disposable copy of the 1A release."""
import base64
import gzip
import hashlib
import json
import pathlib
import shutil
import subprocess
import sys
import tempfile

ROOT = pathlib.Path(__file__).resolve().parents[1]
CURRENT = json.loads((ROOT/'tools/prospectos_asesores_multiprograma_release.json').read_text(encoding='utf8'))
OLD = json.loads((ROOT/'tools/prospectos_asesores_1a_release.json').read_text(encoding='utf8'))
CAMPAIGNS = json.loads((ROOT/'tools/prospectos_campanias_release.json').read_text(encoding='utf8'))
checks=0

def check(condition, name):
    global checks
    if not condition:
        raise AssertionError(name)
    checks += 1

def sha(data):
    return hashlib.sha256(data).hexdigest()

def write(path,data):
    path.parent.mkdir(parents=True,exist_ok=True)
    path.write_bytes(data)

def run(publisher,site,args,success=True):
    result=subprocess.run([sys.executable,str(publisher),'--site',str(site)]+args,
        stdout=subprocess.PIPE,stderr=subprocess.STDOUT,universal_newlines=True)
    if (result.returncode==0)!=success:
        raise AssertionError('Comando inesperado: %s, salida: %s'%(args,result.stdout))
    return result.stdout

check(CURRENT['package']=='impe_prospectos_asesores_multiprograma','identidad del paquete')
check(len(CURRENT['files'])==5,'paquete de cinco archivos locales')
check(all('telefonia' not in item['path'].lower() for item in CURRENT['files']),'no toca telefonía')

with tempfile.TemporaryDirectory(prefix='impe-multiprograma-test-') as temp:
    tmp=pathlib.Path(temp)
    site=tmp/'public_html'; site.mkdir()
    tools=tmp/'tools';tools.mkdir()
    backup=tmp/'backups'
    pub=tools/'publicar_prospectos_asesores_multiprograma.py'
    shutil.copy2(str(ROOT/'tools/publicar_prospectos_asesores_multiprograma.py'),str(pub))
    package=json.loads(json.dumps(CURRENT))
    old_files={f['path']:f for f in OLD['files']}
    campaign_files={f['path']:f for f in CAMPAIGNS['files']}
    # Datos ficticios en RolModel: la huella auténtica sigue sellada en el
    # manifiesto real, y la prueba reescribe solo la copia temporal.
    dependencies={
        'app/controllers/ProspectoController.php':base64.b64decode(old_files['app/controllers/ProspectoController.php']['payload_b64']),
        'public/css/prospectos_central.css':gzip.decompress(base64.b64decode(campaign_files['public/css/prospectos_central.css']['target'])),
        'app/models/RolModel.php':b'RolModel personalizado de entorno desechable',
    }
    for dep in package['dependencies']:
        actual=dependencies[dep['path']]
        if dep['path']!='app/models/RolModel.php':
            check(sha(actual)==dep['sha256'],'dependencia conocida: '+dep['path'])
        else:
            dep['sha256']=sha(actual)
        write(site/dep['path'],actual)

    originals={}
    for f in package['files']:
        path=f['path']
        if f['base_sha256'] is None:
            (site/path).parent.mkdir(parents=True,exist_ok=True)
            originals[path]=None
            continue
        previous=base64.b64decode(old_files[path]['payload_b64'])
        check(sha(previous)==f['base_sha256'],'origen 1A: '+path)
        originals[path]=previous
        write(site/path,previous)

    (tools/'prospectos_asesores_multiprograma_release.json').write_text(json.dumps(package,ensure_ascii=False),encoding='utf8')
    check('CHECK COMPATIBLE' in run(pub,site,['--check']),'comprobación inicial')
    check(not backup.exists(),'check sin crear respaldo')
    check('PUBLICADO: 5 archivos' in run(pub,site,['--apply','--backup-root',str(backup)]),'aplicación 1A.2')
    for f in package['files']:
        check(sha((site/f['path']).read_bytes())==f['sha256'],'integridad 1A.2 '+f['path'])
    check('ALREADY APPLIED' in run(pub,site,['--apply','--backup-root',str(backup)]),'aplicación repetida no escribe')
    folders=list(backup.iterdir())
    check(len(folders)==1,'solo un respaldo')
    check('RESTORE COMPLETO' in run(pub,site,['--restore',str(folders[0])]),'restauración exacta')
    for path,data in originals.items():
        actual=(site/path).read_bytes() if (site/path).exists() else None
        check(actual==data,'restauración original '+path)
    write(site/'app/services/ProspectoAsesorConfiguracionService.php',b'Personalizado, no reemplazar')
    check('Conflicto:' in run(pub,site,['--apply','--backup-root',str(backup)],False),'bloquea personalizaciones')
    write(site/'app/services/ProspectoAsesorConfiguracionService.php',originals['app/services/ProspectoAsesorConfiguracionService.php'])
    write(site/'app/models/RolModel.php',b'Dependencia actualizada sin conciliar')
    check('Dependencia diferente:' in run(pub,site,['--check'],False),'bloquea dependencia personalizada')
    check(len(list(backup.iterdir()))==1,'sin escrituras durante fallos')

print('OK: %d verificaciones de release incremental 1A.2.'%checks)
