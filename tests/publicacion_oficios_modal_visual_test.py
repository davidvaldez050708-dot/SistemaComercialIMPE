"""Prueba del despliegue VISUAL aislado del modal: check, conflicto, idempotencia y rollback."""
from pathlib import Path
import hashlib
import json
import subprocess
import sys
import tempfile

ROOT=Path(__file__).resolve().parents[1]
PUB=ROOT/'tools/publicar_oficios_modal_visual_hostinger.py'
MANIFEST=ROOT/'tools/oficios_modal_visual_manifest.json'
spec=json.loads(MANIFEST.read_text(encoding='utf-8'))
assert spec['schema']=='impe.vinculacion.oficios.visual.v1'
assert {x['path'] for x in spec['files']}=={
    'public/javascript/oficios_vinculacion.js',
    'public/css/oficios_selector_compacto.css'
}

with tempfile.TemporaryDirectory(prefix='impe-oficios-modal-visual-') as tmp:
    temp=Path(tmp)
    site=temp/'public_html'
    site.mkdir()
    (site/'index.php').write_text('<?php // sitio aislado',encoding='utf-8')
    backups=temp/'respaldos'
    for row in spec['files']:
        path=site/row['path']
        path.parent.mkdir(parents=True,exist_ok=True)
        base=subprocess.check_output(['git','show',spec['base']+':'+row['path']],cwd=str(ROOT))
        path.write_bytes(base+b'\n/* PERSONALIZACION LOCAL INTACTA */\n')

    def snapshot():
        return {p.relative_to(site).as_posix():p.read_bytes()
                for p in site.rglob('*') if p.is_file()}

    def run(*arguments,ok=True):
        p=subprocess.run([sys.executable,str(PUB),'--site',str(site),
            '--backup-root',str(backups),'--manifest',str(MANIFEST)]+list(arguments),
            stdout=subprocess.PIPE,stderr=subprocess.PIPE)
        message=(p.stdout+p.stderr).decode('utf-8','replace')
        assert (p.returncode==0)==ok,message
        return message

    before=snapshot()
    assert 'CHECK OK' in run('--check')
    assert snapshot()==before, 'La comprobación modificó archivos del sitio'
    assert not backups.exists(), 'La comprobación creó un respaldo'
    js=site/'public/javascript/oficios_vinculacion.js'
    original=js.read_bytes()
    h=next(x for x in spec['files'] if x['path']==js.relative_to(site).as_posix())['changes'][0]
    first=h['before'].encode('utf-8')
    assert original.count(first)==1
    js.write_bytes(original.replace(first,b'/* CONFLICTO LOCAL NO REEMPLAZAR */',1))
    mismatch=snapshot()
    assert 'CONFLICTO' in run('--check',ok=False)
    assert snapshot()==mismatch and not backups.exists()
    js.write_bytes(original)

    result=run('--apply')
    assert 'APLICADO:' in result and 'RESPALDO:' in result,result
    after=snapshot()
    for row in spec['files']:
        assert after[row['path']]!=before[row['path']]
        assert b'PERSONALIZACION LOCAL INTACTA' in after[row['path']]
    assert 'YA INSTALADO' in run('--check')
    assert 'YA INSTALADO' in run('--apply')
    folders=[x for x in backups.iterdir() if x.is_dir()]
    assert len(folders)==1
    js.write_bytes(js.read_bytes()+b'\n/* MODIFICACION POSTERIOR */')
    assert 'CONFLICTO' in run('--restore',str(folders[0]),ok=False)
    js.write_bytes(after[js.relative_to(site).as_posix()])
    assert 'RESTAURADO:' in run('--restore',str(folders[0]))
    assert snapshot()==before, 'La restauración no recuperó los dos archivos exactos'
print('PASS oficios visual: JS/CSS solamente, preflight, bloqueo de conflicto, respaldo y restauración')
