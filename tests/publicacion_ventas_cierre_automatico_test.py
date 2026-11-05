"""Prueba aislada del publicador comercial: huellas, Python 3.6, rollback."""
import contextlib
import hashlib
import importlib.util
import io
import pathlib
import subprocess
import sys
import tempfile
from unittest.mock import patch

ROOT=pathlib.Path(__file__).resolve().parents[1]
SPEC=importlib.util.spec_from_file_location(
    'pub_cierre', ROOT/'tools/publicar_ventas_cierre_automatico_hostinger.py')
pub=importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(pub)

assert len(pub.FILES)==7
assert len(pub.DEPS)==1
assert pub.REVISION=='82b2cc8c71f4ad104e7f7b72d7e17407703f0632'
assert pub.blob((ROOT/'app/services/TelefoniaVentasCierreApiVerificado.php').read_bytes())==pub.FILES['app/services/TelefoniaVentasCierreApiVerificado.php'][1]

def old_content(path):
    ref='2b65829adcb30d4e4b92e2f8adc79a7457e76f9d' if path in (
        'app/services/TelefoniaResultadoVentasService.php',
        'public/javascript/telefonia_ventas_resultados.js') else (
        '28afc28b330a59e88660179e592b6541a3804d2a')
    return subprocess.check_output(['git','show',ref+':'+path],cwd=str(ROOT))

def execute(site, backups, *mode):
    argv=['pub','--site',str(site),'--backup-root',str(backups)]+list(mode)
    with patch.object(sys,'argv',argv), contextlib.redirect_stdout(io.StringIO()) as out:
        pub.main()
    return out.getvalue()

with tempfile.TemporaryDirectory(prefix='impe-ventas-cierre-seguro-') as temp:
    root=pathlib.Path(temp)
    site=root/'sitio'
    site.mkdir()
    (site/'index.php').write_text('<?php')
    backup=root/'privado'
    deps={}
    for path in pub.DEPS:
        file=site/path
        file.parent.mkdir(parents=True,exist_ok=True)
        file.write_bytes(b'// personalizado: no modificar')
        deps[path]=pub.digest(file.read_bytes())
    pub.DEPS=deps
    original={}
    for path,(before,after) in pub.FILES.items():
        file=site/path
        file.parent.mkdir(parents=True,exist_ok=True)
        if before is None:
            original[path]=None
        else:
            raw=old_content(path)
            original[path]=raw
            file.write_bytes(raw)
            if before.startswith('sha256:'):
                assert pub.digest(raw)==before[7:],path
            else:
                assert pub.blob(raw)==before[5:],path
        assert pub.blob((ROOT/path).read_bytes())==after,path
    pub.fetch=lambda rel, sha:(ROOT/rel).read_bytes()
    snap={p:p.read_bytes() for p in site.rglob('*') if p.is_file()}
    assert 'SITIO INTACTO' in execute(site,backup,'--check')
    assert not backup.exists()
    assert all(p.read_bytes()==data for p,data in snap.items())
    assert 'APLICADO' in execute(site,backup,'--apply')
    for path in pub.FILES:
        assert (site/path).read_bytes()==(ROOT/path).read_bytes(),path
    folder=next(backup.iterdir())
    assert 'YA INSTALADO' in execute(site,backup,'--apply')
    assert len(list(backup.iterdir()))==1
    target=site/'public/javascript/telefonia_ventas_resultados.js'
    target.write_bytes(target.read_bytes()+b'\n// cambio concurrente')
    try:
        execute(site,backup,'--restore',str(folder))
        raise AssertionError('No debe restaurar sobre cambios ajenos')
    except RuntimeError as error:
        assert 'VERSIÓN DIFERENTE' in str(error)
    target.write_bytes((ROOT/'public/javascript/telefonia_ventas_resultados.js').read_bytes())
    assert 'RESTAURADO' in execute(site,backup,'--restore',str(folder))
    for path,data in original.items():
        file=site/path
        assert file.read_bytes()==data if data is not None else not file.exists(),path
    for path in deps:
        assert (site/path).read_bytes()==b'// personalizado: no modificar'
    file=site/'public/javascript/telefonia_host.js'
    file.write_bytes(file.read_bytes()+b'\n// variante desconocida')
    try:
        execute(site,backup,'--check')
        raise AssertionError('Se aceptó variante no reconocida')
    except RuntimeError as error:
        assert 'VERSIÓN DIFERENTE' in str(error)
print('PASS: 7 archivos, integridad Git, Python3.6, no SQL, no escrituras en check, respaldo, rollback y conflictos.')
