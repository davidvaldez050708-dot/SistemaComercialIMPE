#!/usr/bin/env python3
"""Release 1B/1C: check, apply, exact restore on a disposable site."""
import json
import pathlib
import hashlib
import shutil
import subprocess
import tempfile
import sys

ROOT=pathlib.Path(__file__).resolve().parents[1]
MANIFEST=json.loads((ROOT/'tools/prospectos_motores_distribucion_release.json').read_text(encoding='utf8'))
PUBLISHER=ROOT/'tools/publicar_prospectos_motores.py'
count=0

def check(ok,msg):
    global count
    if not ok:raise AssertionError(msg)
    count+=1

def sha(data):return hashlib.sha256(data).hexdigest()

def save(path,data):
    path.parent.mkdir(parents=True,exist_ok=True)
    path.write_bytes(data)

def run(pub,site,params,success=True):
    result=subprocess.run([sys.executable,str(pub),'--site',str(site)]+params,
        stdout=subprocess.PIPE,stderr=subprocess.STDOUT,universal_newlines=True)
    if (result.returncode==0)!=success:raise AssertionError(result.stdout)
    return result.stdout

check(MANIFEST['package']=='impe_prospectos_motores_distribucion','identidad')
check(len(MANIFEST['files'])==5,'solo 5 archivos de motores')
check(len(MANIFEST['dependencies'])==5,'cinco dependencias protegidas')
check(all(item['path'].startswith('app/services/') for item in MANIFEST['files']),
      'publicación limitada a app/services')
for item in MANIFEST['files']:
    check(sha((ROOT/item['path']).read_bytes())==item['sha256'],
        'SHA del repositorio '+item['path'])
with tempfile.TemporaryDirectory(prefix='impe-motores-release-test-') as folder:
    root=pathlib.Path(folder)
    site=root/'site';site.mkdir()
    scripts=root/'scripts';scripts.mkdir()
    backups=root/'backups'
    pub=scripts/'publicar_prospectos_motores.py'
    shutil.copy2(str(PUBLISHER),str(pub))
    manifest=json.loads(json.dumps(MANIFEST))
    originals={}
    for dep in manifest['dependencies']:
        raw=('DEPENDENCIA_FICTICIA_NO_MODIFICAR '+dep['path']).encode()
        dep['sha256']=sha(raw)
        save(site/dep['path'],raw)
        originals[dep['path']]=raw
    for item in manifest['files']:
        path=item['path']
        if item['base_sha256']:
            raw=('BASE_FICTICIA_PERSONALIZADA '+path).encode()
            item['base_sha256']=sha(raw)
            originals[path]=raw
            save(site/path,raw)
        else:
            originals[path]=None
    (scripts/'prospectos_motores_distribucion_release.json').write_text(
        json.dumps(manifest),encoding='utf8')
    check('CHECK COMPATIBLE' in run(pub,site,['--check']),'preflight')
    check(not backups.exists(),'check no crea respaldo')
    check('PUBLICADO: 5 archivos' in run(pub,site,
         ['--apply','--backup-root',str(backups)]),'publicación sólo cinco PHP')
    for item in manifest['files']:
        check(sha((site/item['path']).read_bytes())==item['sha256'],
            'publicado '+item['path'])
    check('ALREADY APPLIED' in run(pub,site,['--apply','--backup-root',str(backups)]),
        'idempotencia')
    folders=list(backups.iterdir())
    check(len(folders)==1,'único respaldo')
    check('RESTORE COMPLETO' in run(pub,site,['--restore',str(folders[0])]),
        'restauración completa')
    for path,original in originals.items():
        actual=(site/path).read_bytes() if (site/path).exists() else None
        check(actual==original,'restauración exacta '+path)
    path=site/'app/services/ProspectoMetaImportador.php'
    save(path,b'Personalizacion Hostinger concurrente')
    check('Conflicto:' in run(pub,site,['--check'],False),
        'conflicto protegido importador')
    save(path,originals['app/services/ProspectoMetaImportador.php'])
    dep=site/'app/helpers/ProspectoCentralDatos.php'
    save(dep,b'Datos helper modificado')
    check('Dependencia diferente:' in run(pub,site,['--check'],False),
        'dependencia protegida')
    check(len(list(backups.iterdir()))==1,'no respalda en conflicto')
print('OK: %d verificaciones de paquete selectivo sin Hostinger ni SQL.'%count)
