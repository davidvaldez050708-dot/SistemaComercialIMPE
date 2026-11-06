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

# Un publicador histórico debe probar su contenido sellado, aunque el código
# actual evolucione. La publicación nueva tiene su propio contrato de hashes.
def released(path):
 return subprocess.check_output(['git','cat-file','blob',pub.FILES[path][1]],cwd=str(ROOT))


assert len(pub.FILES)==8
assert len(pub.DEPS)==1
assert pub.REVISION=='d143895ac021b3daa3cb81db37a9f044107fff45'
assert pub.blob(released('app/services/TelefoniaVentasCierreApiVerificado.php'))==pub.FILES['app/services/TelefoniaVentasCierreApiVerificado.php'][1]

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
                if pub.digest(raw)!=before[7:]:
                    # Esta ruta del sitio tiene personalizaciones Hostinger que
                    # no están en la instantánea Git. Sólo la PRUEBA utiliza
                    # el archivo histórico local; el publicador conserva su
                    # huella real de Hostinger y no acepta archivos distintos.
                    assert path=='app/services/TelefoniaVentasLlamadasService.php'
                    pub.FILES[path]=('sha256:'+pub.digest(raw),after)
            else:
                assert pub.blob(raw)==before[5:],path
        assert pub.blob(released(path))==after,path
    pub.fetch=lambda rel, sha:released(rel)
    snap={p:p.read_bytes() for p in site.rglob('*') if p.is_file()}
    assert 'SITIO INTACTO' in execute(site,backup,'--check')
    assert not backup.exists()
    assert all(p.read_bytes()==data for p,data in snap.items())
    assert 'APLICADO' in execute(site,backup,'--apply')
    for path in pub.FILES:
        assert (site/path).read_bytes()==released(path),path
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
    target.write_bytes(released('public/javascript/telefonia_ventas_resultados.js'))
    assert 'RESTAURADO' in execute(site,backup,'--restore',str(folder))
    for path,data in original.items():
        file=site/path
        assert file.read_bytes()==data if data is not None else not file.exists(),path
    for path in deps:
        assert (site/path).read_bytes()==b'// personalizado: no modificar'
    # Hostinger tiene un controlador con personalizaciones ajenas al método
    # de Ventas. El publicador debe fusionar sólo detalleVenta y restaurarlo.
    ctl=site/'app/controllers/TelefoniaController.php'
    native=ctl.read_bytes()
    custom=native.replace(b'<?php',b'<?php\n/* PERSONALIZACION HOSTINGER: NO CAMBIAR */',1)
    ctl.write_bytes(custom)
    check=execute(site,backup,'--check')
    assert 'CONCILIADO (SOLO detalleVenta)' in check,check
    assert ctl.read_bytes()==custom,'--check nunca modifica producción'
    before_folders=set(backup.iterdir())
    assert 'APLICADO' in execute(site,backup,'--apply')
    updated=ctl.read_bytes()
    assert b'PERSONALIZACION HOSTINGER: NO CAMBIAR' in updated
    assert pub.CONTROLLER_NEW_METHOD.encode('utf-8') in updated
    assert pub.CONTROLLER_OLD_METHOD.encode('utf-8') not in updated
    assert 'YA CONCILIADO' in execute(site,backup,'--check')
    latest=list(set(backup.iterdir())-before_folders)
    assert len(latest)==1
    assert 'RESTAURADO' in execute(site,backup,'--restore',str(latest[0]))
    assert ctl.read_bytes()==custom,'restauración conserva personalización exacta'
    # En controladores con detalleVenta distinto no se adivina ni reemplaza.
    modified=custom.replace(b'public function detalleVenta()',b'public function detalleVenta() /* LOGICA LOCAL */',1)
    ctl.write_bytes(modified)
    try:
        execute(site,backup,'--check')
        raise AssertionError('Se aceptó un método detalleVenta distinto sin revisar')
    except RuntimeError as e:
        assert 'CONFLICTO CONTROLADOR' in str(e),str(e)
    assert ctl.read_bytes()==modified
    ctl.write_bytes(modified)
    file=site/'public/javascript/telefonia_host.js'
    file.write_bytes(file.read_bytes()+b'\n// variante desconocida')
    try:
        execute(site,backup,'--check')
        raise AssertionError('Se aceptaron dos variantes no reconocidas')
    except RuntimeError as error:
        assert 'CONFLICTO CONTROLADOR' in str(error)
        assert 'VERSIÓN DIFERENTE' in str(error)
        assert '2 incompatibilidad(es)' in str(error)
    assert ctl.read_bytes()==modified
    assert file.read_bytes().endswith(b'// variante desconocida')
print('PASS: 8 archivos, integridad Git, Python3.6, conciliación selectiva, informe de todos los conflictos y rollback sin SQL.')
