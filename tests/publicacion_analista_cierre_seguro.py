"""Publicación de cierre de Analista en carpeta temporal, sin datos reales."""
import hashlib
import json
from pathlib import Path
import subprocess
import sys
import tempfile

ROOT=Path(__file__).resolve().parents[1]
SCRIPT=ROOT/'tools/publicar_analista_cierre_seguro.py'
MANIFEST=ROOT/'tools/analista_cierre_seguro_manifest.json'
spec=json.loads(MANIFEST.read_text(encoding='utf-8'))
source=spec['source']
assert len(spec['files'])==4 and len(set(x['path'] for x in spec['files']))==4
with tempfile.TemporaryDirectory(prefix='impe-cierre-safe-tests-') as tmp:
    root=Path(tmp)
    site=root/'site';site.mkdir()
    (site/'index.php').write_text('<?php // fixture',encoding='utf8')
    backs=root/'private'
    for dep in ['app/services/ZadarmaCallLookupService.php','prueba_telefonia/api/zadarma_estado_llamada.php']:
        path=site/dep;path.parent.mkdir(parents=True,exist_ok=True)
        path.write_text('<?php // dependencia de prueba',encoding='utf8')
    original={}
    for row in spec['files']:
        path=site/row['path'];path.parent.mkdir(parents=True,exist_ok=True)
        before=subprocess.check_output(['git','show',source+':'+row['path']],cwd=str(ROOT))
        original[row['path']]=before
        path.write_bytes(before + b'\n// LOCAL: conservar customizacion\n')
    def snapshot():
        return {p.relative_to(site).as_posix():p.read_bytes()
                for p in site.rglob('*') if p.is_file()}
    def run(*args,**opts):
        p=subprocess.run([sys.executable,str(SCRIPT),'--site',str(site),
            '--backup-root',str(backs),'--manifest',str(MANIFEST)]+list(args),
            stdout=subprocess.PIPE,stderr=subprocess.PIPE)
        msg=(p.stdout+p.stderr).decode('utf8',errors='replace')
        assert (p.returncode==0)==opts.get('ok',True),msg
        return msg
    untouched=snapshot()
    assert 'CHECK OK' in run('--check')
    assert snapshot()==untouched and not backs.exists(),'Preflight alteró el sitio'
    service=site/spec['files'][1]['path']
    changed=service.read_bytes().replace(b"require_once __DIR__ . '/TelefoniaSalienteAnalistaEstado.php';",b"require_once __DIR__ . '/OtroServicio.php';",1)
    service.write_bytes(changed)
    failed=snapshot()
    assert 'CONFLICTO' in run('--check',ok=False)
    assert snapshot()==failed and not backs.exists(),'Conflicto alteró la instalación'
    service.write_bytes(untouched[spec['files'][1]['path']])
    assert 'APLICADO' in run('--apply')
    after=snapshot()
    for row in spec['files']:
        assert b'// LOCAL: conservar customizacion' in after[row['path']]
        assert after[row['path']]!=untouched[row['path']]
    archive=next(x for x in backs.iterdir() if x.is_dir())
    assert 'YA INSTALADO' in run('--apply')
    assert len([x for x in backs.iterdir() if x.is_dir()])==1
    js=site/'public/javascript/telefonia_analista_historial_pasivo.js'
    js.write_bytes(js.read_bytes()+b'\n// edicion ajena')
    assert 'CONFLICTO' in run('--restore',str(archive),ok=False)
    js.write_bytes(after['public/javascript/telefonia_analista_historial_pasivo.js'])
    assert 'RESTAURADO' in run('--restore',str(archive))
    assert snapshot()==untouched,'No recuperó personalizaciones y permisos originales'
print('PASS publicador cierre: check, rechazo, personalizaciones, instalación, idempotencia y reversión')
