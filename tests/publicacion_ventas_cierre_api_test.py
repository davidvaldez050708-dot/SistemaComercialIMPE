"""Publicación reversible de Ventas, con versiones exactas y dependencias intactas."""
import contextlib,hashlib,importlib.util,io,json,pathlib,subprocess,sys,tempfile
from unittest.mock import patch

ROOT=pathlib.Path(__file__).resolve().parents[1]
SPEC=importlib.util.spec_from_file_location('cierre_api',ROOT/'tools/publicar_ventas_cierre_api_verificado.py')
pub=importlib.util.module_from_spec(SPEC);SPEC.loader.exec_module(pub)

# Un publicador histórico debe probar su contenido sellado, aunque el código
# actual evolucione. La publicación nueva tiene su propio contrato de hashes.
def released(path):
 return subprocess.check_output(['git','cat-file','blob',pub.FILES[path][1]],cwd=str(ROOT))

assert pub.REVISION=='6f9b6156954ba5504529a0fc18837076a20ad82f'
assert len(pub.FILES)==3 and len(pub.DEPS)==2
assert 'app/services/TelefoniaVentasLlamadasService.php' in pub.DEPS
assert 'app/services/TelefoniaVentasEvidenciaService.php' in pub.DEPS
def run(site,backup,*mode):
    argv=['publicar_ventas_cierre_api_verificado.py','--site',str(site),
          '--backup-root',str(backup)]+list(mode)
    with patch.object(sys,'argv',argv),contextlib.redirect_stdout(io.StringIO()) as output:
        pub.main()
    return output.getvalue()
with tempfile.TemporaryDirectory(prefix='impe-ventas-cierre-api-') as dir:
    home=pathlib.Path(dir);site=home/'site';site.mkdir();(site/'index.php').write_text('<?php')
    backup=home/'backups'
    for path in pub.DEPS:
        file=site/path;file.parent.mkdir(parents=True,exist_ok=True)
        file.write_bytes(b'// dependencia local protegida')
    pub.DEPS={path:pub.digest((site/path).read_bytes()) for path in pub.DEPS}
    old={}
    for path,(sha_before,after) in pub.FILES.items():
        file=site/path;file.parent.mkdir(parents=True,exist_ok=True)
        if sha_before:
            content=subprocess.check_output(['git','show',
                '2b65829adcb30d4e4b92e2f8adc79a7457e76f9d:'+path],cwd=ROOT)
            assert pub.digest(content)==sha_before,path
            old[path]=content;file.write_bytes(content)
        else:old[path]=None
        assert pub.blob(released(path))==after,path
    pub.fetch=lambda rel,sha:released(rel)
    before={str(x):x.read_bytes() for x in site.rglob('*') if x.is_file()}
    assert 'SITIO INTACTO' in run(site,backup,'--check')
    assert not backup.exists()
    assert all(x.read_bytes()==before[str(x)] for x in site.rglob('*') if x.is_file())
    assert 'APLICADO' in run(site,backup,'--apply')
    assert all((site/path).read_bytes()==released(path) for path in pub.FILES)
    folder=next(backup.iterdir())
    assert 'YA INSTALADO' in run(site,backup,'--apply')
    assert len(list(backup.iterdir()))==1
    target=site/'public/javascript/telefonia_ventas_resultados.js'
    target.write_bytes(target.read_bytes()+b'\n// modificacion concurrente')
    try:run(site,backup,'--restore',str(folder));raise AssertionError('Reversión insegura')
    except RuntimeError as e:assert 'VERSIÓN DIFERENTE' in str(e)
    target.write_bytes(released('public/javascript/telefonia_ventas_resultados.js'))
    assert 'RESTAURADO' in run(site,backup,'--restore',str(folder))
    assert all((site/path).read_bytes()==content if content is not None else not (site/path).exists() for path,content in old.items())
    assert all((site/path).read_bytes()==before[str(site/path)] for path in pub.DEPS)
    target=site/'app/services/TelefoniaResultadoVentasService.php'
    target.write_bytes(target.read_bytes()+b'\n// edicion local')
    try:run(site,backup,'--check');raise AssertionError('Se aceptó archivo modificado')
    except RuntimeError as e:assert 'VERSIÓN DIFERENTE' in str(e)
    assert target.read_bytes().endswith(b'// edicion local')
print('PASS: versiones precisas, 3 archivos, 2 dependencias protegidas, sin SQL, respaldo, restauración y rechazo de conflictos.')
