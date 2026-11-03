"""Publicación offline sobre réplicas temporales. Nunca consulta producción."""
from pathlib import Path
from unittest.mock import patch
import contextlib
import importlib.util
import io
import json
import sys
import tempfile

ROOT=Path(__file__).resolve().parents[1]
spec=importlib.util.spec_from_file_location('pub',ROOT/'tools/publicar_prospectos_campanias.py')
pub=importlib.util.module_from_spec(spec);spec.loader.exec_module(pub)
manifest=json.loads((ROOT/'tools/prospectos_campanias_release.json').read_text(encoding='utf-8'))
assert tuple(row['path'] for row in manifest['files'])==pub.FILES
for row in manifest['files']:
    assert pub.unpack(row['target'])==(ROOT/row['path']).read_bytes().replace(b'\r\n',b'\n'),row['path']
    if row['base'] is not None:assert pub.sha(pub.unpack(row['base']))==row['base_sha256']
def run(site,backup,*flags):
    with patch.object(sys,'argv',['pub','--site',str(site),'--backup-root',str(backup),*flags]),contextlib.redirect_stdout(io.StringIO()):pub.main()
def snapshot(site):return {str(p.relative_to(site)):p.read_bytes() for p in site.rglob('*') if p.is_file()}
for eol,custom in [(b'\n',False),(b'\r\n',False),(b'\n',True),(b'\r\n',True)]:
    with tempfile.TemporaryDirectory(prefix='campanias-') as t:
        site=Path(t)/'site';backup=Path(t)/'private';site.mkdir();(site/'index.php').write_bytes(b'<?php')
        for row in manifest['files']:
            version=row['variants'][0] if custom and row.get('variants') else row
            if version['base'] is not None:
                p=site/row['path'];p.parent.mkdir(parents=True,exist_ok=True);p.write_bytes(pub.unpack(version['base']).replace(b'\n',eol))
        for row in manifest['dependencies']:
            if row.get('when_role_hashes'):
                if not custom:continue
                data=pub.unpack(row['fixture'])
            else:data=(ROOT/row['path']).read_bytes()
            p=site/row['path'];p.parent.mkdir(parents=True,exist_ok=True);p.write_bytes(data)
        sentinel=site/'app/views/aliados/personalizado.php';sentinel.parent.mkdir(parents=True,exist_ok=True);sentinel.write_bytes(b'<?php // personalizacion de aliados')
        original=snapshot(site);run(site,backup,'--check');assert snapshot(site)==original
        bad=site/pub.FILES[-1];bad.write_bytes(bad.read_bytes()+b'\n// otra variante')
        changed=snapshot(site)
        try:run(site,backup,'--apply');raise AssertionError('Conflicto aceptado')
        except RuntimeError as e:assert 'VERSIÓN DIFERENTE' in str(e)
        assert snapshot(site)==changed;bad.write_bytes(original[str(bad.relative_to(site))])
        if custom:
            policy=site/'app/helpers/SupervisionRolePolicy.php';data=policy.read_bytes();policy.write_bytes(data+b'\n// variante')
            changed=snapshot(site)
            try:run(site,backup,'--apply');raise AssertionError('Dependencia desconocida aceptada')
            except RuntimeError as e:assert 'DEPENDENCIA DIFERENTE' in str(e)
            assert snapshot(site)==changed;policy.write_bytes(data)
        # Un fallo tras la primera escritura devuelve todos los archivos al estado previo.
        atomic=pub.atomic;calls=[]
        def fail(path,data,mode=0o644):
            calls.append(path)
            if len(calls)==2:raise OSError('Fallo de prueba')
            return atomic(path,data,mode)
        with patch.object(pub,'atomic',side_effect=fail):
            try:run(site,backup,'--apply');raise AssertionError('No hubo fallo')
            except OSError:pass
        assert snapshot(site)==original,'Rollback completo'
        run(site,backup,'--apply');installed=snapshot(site);run(site,backup,'--apply');assert snapshot(site)==installed
        saved=sorted(backup.glob('*/respaldo.json'))[-1].parent
        bad.write_bytes(bad.read_bytes()+b'\n// posterior')
        changed=snapshot(site)
        try:run(site,backup,'--restore',str(saved));raise AssertionError('Restauró encima de variante posterior')
        except RuntimeError as e:assert 'modificado' in str(e)
        assert snapshot(site)==changed;bad.write_bytes(installed[str(bad.relative_to(site))])
        run(site,backup,'--restore',str(saved));assert snapshot(site)==original
print('PASS paquete: dieciséis archivos exactos, LF/CRLF, check sin escrituras, conflictos, rollback, idempotencia, respaldo/restauración y permisos intactos.')
