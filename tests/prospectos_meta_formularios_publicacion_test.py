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
spec=importlib.util.spec_from_file_location('pub',ROOT/'tools/publicar_prospectos_meta_formularios.py')
pub=importlib.util.module_from_spec(spec);spec.loader.exec_module(pub)
manifest=json.loads((ROOT/'tools/prospectos_meta_formularios_release.json').read_text(encoding='utf-8'))
assert tuple(row['path'] for row in manifest['files'])==pub.FILES
for row in manifest['files']:
    assert pub.unpack(row['target'])==(ROOT/row['path']).read_bytes().replace(b'\r\n',b'\n'),row['path']
    if row['base'] is not None:assert pub.sha(pub.unpack(row['base']))==row['base_sha256']
def run(site,backup,*flags):
    with patch.object(sys,'argv',['pub','--site',str(site),'--backup-root',str(backup),*flags]),contextlib.redirect_stdout(io.StringIO()):pub.main()
def snapshot(site):return {str(p.relative_to(site)):p.read_bytes() for p in site.rglob('*') if p.is_file()}
for eol in [b'\n',b'\r\n']:
    with tempfile.TemporaryDirectory(prefix='meta-formularios-') as t:
        site=Path(t)/'site';backup=Path(t)/'private';site.mkdir();(site/'index.php').write_bytes(b'<?php')
        for row in manifest['files']:
            if row['base'] is not None:
                p=site/row['path'];p.parent.mkdir(parents=True,exist_ok=True);p.write_bytes(pub.unpack(row['base']).replace(b'\n',eol))
        sentinel=site/'app/models/RolModel.php';sentinel.parent.mkdir(parents=True,exist_ok=True);sentinel.write_bytes(b'<?php // personalizacion de permisos')
        original=snapshot(site);run(site,backup,'--check');assert snapshot(site)==original
        bad=site/pub.FILES[-1];bad.write_bytes(bad.read_bytes()+b'\n// otra variante')
        changed=snapshot(site)
        try:run(site,backup,'--apply');raise AssertionError('Conflicto aceptado')
        except RuntimeError as e:assert 'VERSIÓN DIFERENTE' in str(e)
        assert snapshot(site)==changed;bad.write_bytes(original[str(bad.relative_to(site))])
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
print('PASS paquete: siete archivos exactos, LF/CRLF, check sin escrituras, conflictos, rollback, idempotencia, respaldo/restauración y permisos intactos.')
