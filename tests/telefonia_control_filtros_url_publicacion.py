"""Sitio de prueba con la base exacta de producción; no usa SQL ni PBX."""
from pathlib import Path
import base64,contextlib,gzip,io,json,sys,tempfile
ROOT=Path(__file__).resolve().parents[1];sys.path.insert(0,str(ROOT/'tools'))
import publicar_telefonia_filtros_url as p
package=p.manifest(ROOT/'tools/telefonia_filtros_url_release.json')
fixture=json.loads((ROOT/'tests/fixtures/telefonia_control_filtros_url_base.json').read_text())
original=gzip.decompress(base64.b64decode(fixture['gzip']));assert p.digest(original)==fixture['sha256']
def deny(fn):
    try:fn()
    except RuntimeError:return
    raise AssertionError('Se esperaba bloqueo')
with tempfile.TemporaryDirectory(prefix='impe-filtros-url-test-') as tmp:
    site=Path(tmp)/'public_html';backups=Path(tmp)/'backups';site.mkdir();(site/'index.php').write_text('<?php // fixture')
    dest=site/p.PATHS[0];dest.parent.mkdir(parents=True);dest.write_bytes(original)
    untouched=site/'config/db_connection.php';untouched.parent.mkdir();untouched.write_text('<?php throw new Exception("No debe ejecutarse SQL");')
    preserved=untouched.read_bytes()
    with contextlib.redirect_stdout(io.StringIO()):
        rows=p.prepare(site,package);assert not backups.exists()
        assert rows[0]['after']==(ROOT/p.PATHS[0]).read_bytes().replace(b'\r\n',b'\n')
        dest.write_bytes(original+b'\n// cambio ajeno');deny(lambda:p.apply(site,backups,package));dest.write_bytes(original)
        assert not backups.exists()
        p.apply(site,backups,package);p.apply(site,backups,package)
        copies=[x for x in backups.iterdir() if x.is_dir()];assert len(copies)==1
        data=dest.read_bytes();dest.write_bytes(data+b'\n// posterior');deny(lambda:p.restore(site,backups,copies[0]));dest.write_bytes(data)
        p.restore(site,backups,copies[0]);assert dest.read_bytes()==original
        real=p.atomic
        def broken(*args):raise OSError('Fallo sintético antes de sustituir el archivo')
        p.atomic=broken
        try:
            try:p.apply(site,backups,package)
            except OSError:pass
            else:raise AssertionError('Se esperaba fallo')
        finally:p.atomic=real
        assert dest.read_bytes()==original and untouched.read_bytes()==preserved
print('CHECK_OK entrega: un archivo, CHECK sin escrituras, rechazo de personalizaciones, respaldo, APPLY idempotente, reversión protegida y fallo de publicación; configuración intacta, sin SQL ni PBX.')
