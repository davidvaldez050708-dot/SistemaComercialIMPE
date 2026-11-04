"""Publicación aislada sin llamadas reales, SQL ni tocar el sitio público."""
import json
from pathlib import Path
import subprocess
import sys
import tempfile

ROOT=Path(__file__).resolve().parents[1]
MANIFEST=ROOT/'tools/analista_historial_overlay_manifest.json'
PUBLISH=ROOT/'tools/publicar_analista_historial_overlay.py'
spec=json.loads(MANIFEST.read_text(encoding='utf8'))
assert len(spec['files'])==8
with tempfile.TemporaryDirectory(prefix='impe-analista-overlay-test-') as d:
    root=Path(d);site=root/'site';site.mkdir()
    (site/'index.php').write_text('<?php // CRM de prueba',encoding='utf8')
    backup=root/'private'
    originals={}
    for row in spec['files']:
        path=site/row['path']
        if row['action']=='patch':
            old=subprocess.check_output(['git','show',spec['base']+':'+row['path']],cwd=str(ROOT))
            path.parent.mkdir(parents=True,exist_ok=True)
            # Cambio local permitido fuera del hunk; no debe eliminarse.
            path.write_bytes(old+b'\n/* PERSONALIZACION LOCAL INTACTA */\n')
            originals[row['path']]=path.read_bytes()
        else:
            originals[row['path']]=None
    def snapshot():
        return {p.relative_to(site).as_posix():p.read_bytes()
                for p in site.rglob('*') if p.is_file()}
    def run(*args,ok=True):
        x=subprocess.run([sys.executable,str(PUBLISH),'--site',str(site),
            '--backup-root',str(backup),'--manifest',str(MANIFEST)]+list(args),
            stdout=subprocess.PIPE,stderr=subprocess.PIPE)
        out=(x.stdout+x.stderr).decode('utf-8','replace')
        if (x.returncode==0)!=ok:
            raise AssertionError(out)
        return out
    initial=snapshot()
    assert 'CHECK OK' in run('--check')
    assert snapshot()==initial and not backup.exists(), 'Preflight no debe escribir nada'
    target=site/spec['files'][2]['path']
    old=target.read_bytes()
    h=spec['files'][2]['changes'][0]['before'].encode()
    assert old.count(h)==1
    target.write_bytes(old.replace(h,b'// CONFLICTO AJENO',1))
    broken=snapshot()
    assert 'CONFLICTO' in run('--check',ok=False)
    assert snapshot()==broken and not backup.exists()
    target.write_bytes(old)
    assert 'APLICADO' in run('--apply')
    after=snapshot()
    for row in spec['files']:
        path=site/row['path']
        assert path.is_file(),row['path']
        if row['action']=='patch':
            assert b'/* PERSONALIZACION LOCAL INTACTA */' in path.read_bytes()
        else:
            assert path.read_bytes()==row['content'].encode()
    archives=[p for p in backup.iterdir() if p.is_dir()]
    assert len(archives)==1
    assert 'YA INSTALADO' in run('--check')
    assert 'YA INSTALADO' in run('--apply')
    assert len([p for p in backup.iterdir() if p.is_dir()])==1
    target.write_bytes(target.read_bytes()+b'\n// CAMBIO CONCURRENTE\n')
    assert 'CONFLICTO' in run('--restore',str(archives[0]),ok=False)
    target.write_bytes(after[spec['files'][2]['path']])
    assert 'RESTAURADO' in run('--restore',str(archives[0]))
    assert snapshot()==initial, 'Restauración íntegra incluso archivo nuevo'
print('PASS publicador: 8 archivos, cambios locales, idempotencia, conflicto y reversión')
