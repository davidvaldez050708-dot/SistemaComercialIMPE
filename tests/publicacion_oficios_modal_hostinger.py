"""Preflight, personalizaciones, conflicto, aplicación y rollback en carpeta aislada."""
import json
from pathlib import Path
import subprocess
import sys
import tempfile

ROOT=Path(__file__).resolve().parents[1]
PUB=ROOT/'tools/publicar_oficios_modal_hostinger.py'
MANIFEST=ROOT/'tools/oficios_modal_documento_final_manifest.json'
spec=json.loads(MANIFEST.read_text(encoding='utf-8'))
assert len(spec['files'])==5
with tempfile.TemporaryDirectory(prefix='impe-oficios-seguro-') as td:
    base=Path(td)
    site=base/'sitio';site.mkdir()
    (site/'index.php').write_text('<?php // CRM aislado',encoding='utf-8')
    backups=base/'respaldos'
    for row in spec['files']:
        file=site/row['path']
        file.parent.mkdir(parents=True,exist_ok=True)
        original=subprocess.check_output(['git','show',spec['base']+':'+row['path']],cwd=str(ROOT))
        if file.suffix=='.php':
            original+=b'\n// HOSTINGER: personalizacion compatible\n'
        else:
            original=b'/* HOSTINGER: personalizacion compatible */\n'+original
        file.write_bytes(original)
    def snapshot():
        return {p.relative_to(site).as_posix():p.read_bytes()
                for p in site.rglob('*') if p.is_file()}
    def run(*flags,ok=True):
        p=subprocess.run([sys.executable,str(PUB),'--site',str(site),
           '--backup-root',str(backups),'--manifest',str(MANIFEST)]+list(flags),
           stdout=subprocess.PIPE,stderr=subprocess.PIPE)
        value=(p.stdout+p.stderr).decode('utf8','replace')
        if (p.returncode==0)!=ok:
            raise AssertionError(value)
        return value
    initial=snapshot()
    assert 'CHECK OK' in run('--check')
    assert snapshot()==initial and not backups.exists(),'--check debe ser lectura pura'
    f=site/'public/javascript/oficios_vinculacion.js'
    old=f.read_bytes()
    h=next(x for x in spec['files'] if x['path']==f.relative_to(site).as_posix())['changes'][0]['before'].encode('utf8')
    assert old.count(h)==1
    f.write_bytes(old.replace(h,b'// PERSONALIZACION CONFLICTIVA',1))
    conflicted=snapshot()
    assert 'CONFLICTO' in run('--check',ok=False)
    assert snapshot()==conflicted and not backups.exists()
    f.write_bytes(old)
    assert 'APLICADO:' in run('--apply')
    after=snapshot()
    for row in spec['files']:
        changed=after[row['path']]
        assert b'HOSTINGER: personalizacion compatible' in changed,row['path']
        assert changed!=initial[row['path']]
    folders=[p for p in backups.iterdir() if p.is_dir()]
    assert len(folders)==1
    assert 'YA INSTALADO' in run('--check')
    assert 'YA INSTALADO' in run('--apply')
    assert len([p for p in backups.iterdir() if p.is_dir()])==1
    f.write_bytes(f.read_bytes()+b'\n// CAMBIO POSTERIOR\n')
    assert 'CONFLICTO' in run('--restore',str(folders[0]),ok=False)
    f.write_bytes(after[f.relative_to(site).as_posix()])
    assert 'RESTAURADO' in run('--restore',str(folders[0]))
    assert snapshot()==initial,'El rollback debe preservar íntegramente las personalizaciones'
print('PASS publicador oficios: 5 archivos, --check sin escritura, compatibilidad, idempotencia, conflicto y rollback')
