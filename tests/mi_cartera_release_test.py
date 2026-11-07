#!/usr/bin/env python3
"""Prueba de preflight, aplicación, reversión y conflictos de Mi cartera en copia."""
import json
import pathlib
import hashlib
import shutil
import subprocess
import sys
import tempfile

ROOT=pathlib.Path(__file__).resolve().parents[1]
MANIFEST=json.loads((ROOT/'tools/ventas_mi_cartera_release.json').read_text('utf-8'))
SCRIPT=ROOT/'tools/publicar_ventas_mi_cartera.py'
count=0

def check(ok,message):
    global count
    if not ok:raise AssertionError('ERROR '+message)
    count+=1

def sha(data):
    return hashlib.sha256(data).hexdigest()

def save(path,data):
    path.parent.mkdir(parents=True,exist_ok=True)
    path.write_bytes(data)

def run(script,site,params,success=True):
    response=subprocess.run([sys.executable,str(script),'--site',str(site)]+params,
        stdout=subprocess.PIPE,stderr=subprocess.STDOUT,universal_newlines=True)
    if (response.returncode==0)!=success:
        raise AssertionError('Comando %s error: %s'%(params,response.stdout))
    return response.stdout

check(MANIFEST['package']=='impe_ventas_mi_cartera','paquete correcto')
check(len(MANIFEST['files'])==8,'exactamente ocho archivos de despliegue')
check(len(MANIFEST['dependencies'])==3,'tres dependencias sin escritura')
paths=[f['path'] for f in MANIFEST['files']]
check(all('telefonia' not in p.lower() and not p.startswith('database/') for p in paths),
    'sin telefonía, SQL ni configuraciones en payload')
for f in MANIFEST['files']:
    check(sha((ROOT/f['path']).read_bytes())==f['sha256'],'integridad repositorio '+f['path'])

with tempfile.TemporaryDirectory(prefix='impe-mi-cartera-release-test-') as tmp:
    base=pathlib.Path(tmp)
    site=base/'site';site.mkdir()
    scripts=base/'scripts';scripts.mkdir()
    backup=base/'backup'
    pub=scripts/'publicar_ventas_mi_cartera.py'
    shutil.copy2(str(SCRIPT),str(pub))
    package=json.loads(json.dumps(MANIFEST))
    originals={}
    for item in package['dependencies']:
        data=('dependencia privada: '+item['path']).encode('utf-8')
        item['sha256']=sha(data)
        save(site/item['path'],data)
    for item in package['files']:
        if item['base_sha256'] is None:
            originals[item['path']]=None
            if not item['path'].startswith('app/views/mi_cartera/'):
                (site/item['path']).parent.mkdir(parents=True,exist_ok=True)
        else:
            data=('original de producción simulada: '+item['path']).encode('utf-8')
            item['base_sha256']=sha(data)
            originals[item['path']]=data
            save(site/item['path'],data)

    (scripts/'ventas_mi_cartera_release.json').write_text(json.dumps(package),encoding='utf-8')
    missing=site/'app/views/mi_cartera'
    check(not missing.exists(),'carpeta nueva aún no existe')
    output=run(pub,site,['--check'])
    check('CHECK COMPATIBLE' in output,'check inicial')
    check(not backup.exists() and not missing.exists(),'check no crea respaldo ni carpetas')

    output=run(pub,site,['--apply','--backup-root',str(backup)])
    check('PUBLICADO: 8 archivos' in output,'aplicación de ocho archivos')
    for f in package['files']:
        check(sha((site/f['path']).read_bytes())==f['sha256'],'hash después de apply '+f['path'])
    check(missing.is_dir(),'carpeta de vistas se crea solo durante apply')
    check('ALREADY APPLIED' in run(pub,site,['--apply','--backup-root',str(backup)]),'apply idempotente')
    dirs=list(backup.iterdir())
    check(len(dirs)==1,'un respaldo antes de restaurar')
    check('RESTORE COMPLETO' in run(pub,site,['--restore',str(dirs[0])]),'restore completo')
    for p,data in originals.items():
        actual=(site/p).read_bytes() if (site/p).is_file() else None
        check(actual==data,'restauración exacta '+p)

    # Cambio humano en archivo existente impide cualquier apply parcial.
    target=site/'index.php'
    target.write_bytes(b'cambio concurrente manual')
    check('CONFLICTO' in run(pub,site,['--check'],False),'conflicto index detectado')
    target.write_bytes(originals['index.php'])
    dep=site/'app/models/RolModel.php'
    dep.write_bytes(b'rol-model personalizado distinto')
    check('Dependencia diferente' in run(pub,site,['--check'],False),'conflicto en RolModel no permitido')
    check(len(list(backup.iterdir()))==1,'errores no crearon más respaldos')
print('OK: %d comprobaciones del paquete Mi cartera sin Hostinger ni SQL.'%count)
