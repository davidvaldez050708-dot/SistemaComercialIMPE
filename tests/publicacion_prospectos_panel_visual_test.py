"""Paquete visual sobre dos archivos exactos de Hostinger. Sin red ni SQL."""
import base64
import contextlib
import gzip
import hashlib
import importlib.util
import io
import json
from pathlib import Path
import sys
import tempfile
from unittest import mock

ROOT=Path(__file__).resolve().parents[1]
spec=importlib.util.spec_from_file_location('publisher',ROOT/'tools/publicar_prospectos_panel_visual.py')
publisher=importlib.util.module_from_spec(spec)
spec.loader.exec_module(publisher)
manifest=json.loads((ROOT/'tools/prospectos_panel_visual_release.json').read_text(encoding='utf-8'))
expected={
    'public/css/prospectos_ficha_panel.css':'f2aa740e7be2c2df1edf843683cc62b58ac6b954091888206f3959f703770252',
    'public/javascript/prospectos_ficha_panel.js':'13c2106f0688040c742f4f1a1d7a895888a26102916c832529b80bcc9bee05cf',
}
assert [row['path'] for row in manifest['files']]==list(publisher.FILES)
for row in manifest['files']:
    assert row['base_sha256']==expected[row['path']]
    assert publisher.sha(publisher.unpack(row['base']))==row['base_sha256']
    assert publisher.unpack(row['target'])==(ROOT/row['path']).read_bytes().replace(b'\r\n',b'\n')
    assert publisher.sha(publisher.unpack(row['target']))==row['sha256']
for row in manifest['dependencies']:
    assert publisher.sha((ROOT/row['path']).read_bytes().replace(b'\r\n',b'\n'))==row['sha256']

with tempfile.TemporaryDirectory(prefix='prospectos-panel-visual-') as temp:
    site,backup=Path(temp)/'site',Path(temp)/'private'
    site.mkdir();(site/'index.php').write_text('<?php',encoding='utf-8')
    for row in manifest['files']:
        file=site/row['path'];file.parent.mkdir(parents=True,exist_ok=True)
        file.write_bytes(publisher.unpack(row['base']))
    for row in manifest['dependencies']:
        file=site/row['path'];file.parent.mkdir(parents=True,exist_ok=True)
        file.write_bytes((ROOT/row['path']).read_bytes().replace(b'\r\n',b'\n'))
    args=['publisher','--site',str(site),'--backup-root',str(backup)]

    def run(*flags):
        with mock.patch.object(sys,'argv',args+list(flags)),contextlib.redirect_stdout(io.StringIO()):
            publisher.main()

    def snapshot():
        return {p.relative_to(site).as_posix():p.read_bytes() for p in site.rglob('*') if p.is_file()}

    original=snapshot();run('--check');assert snapshot()==original
    for name,message in [(publisher.FILES[0],'VERSIÓN DIFERENTE'),(publisher.DEPENDENCIES[0],'DEPENDENCIA DIFERENTE')]:
        file=site/name;file.write_bytes(original[name]+b'\n/* variante local */')
        before=snapshot()
        try:
            run('--apply');raise AssertionError('Debió bloquear '+name)
        except RuntimeError as e:
            assert message in str(e)
        assert snapshot()==before;file.write_bytes(original[name])
    run('--apply');installed=snapshot()
    assert all((site/n).read_bytes()==(ROOT/n).read_bytes().replace(b'\r\n',b'\n') for n in publisher.FILES)
    assert all(installed[n]==original[n] for n in publisher.DEPENDENCIES)
    run('--apply');assert snapshot()==installed
    saved=next(backup.glob('prospectos-panel-*'))
    run('--restore',str(saved));assert snapshot()==original
    # Un fallo entre los dos recursos deja ambos como estaban.
    real_atomic,writes=publisher.atomic,[0]

    def failing_atomic(*params,**kwargs):
        writes[0]+=1
        if writes[0]==2:raise OSError('Disco simulado')
        return real_atomic(*params,**kwargs)

    with mock.patch.object(publisher,'atomic',failing_atomic):
        try:
            run('--apply');raise AssertionError('Debió revertir')
        except OSError:pass
    assert snapshot()==original
    # La restauración nunca pisa una personalización posterior.
    run('--apply');saved=sorted(backup.glob('prospectos-panel-*'))[-1]
    file=site/publisher.FILES[0];file.write_bytes(file.read_bytes()+b'\n/* posterior */')
    before=snapshot()
    try:
        run('--restore',str(saved));raise AssertionError('Debió proteger el cambio posterior')
    except RuntimeError as e:assert 'modificado' in str(e)
    assert snapshot()==before
print('PASS: dos archivos, bases exactas, dependencias intactas, check, conflictos, instalación, idempotencia, restore protegido y rollback. Sin SQL.')
