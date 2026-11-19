"""Comprobación de la entrega exacta y reversible sin conexiones al CRM."""
from pathlib import Path
import base64,contextlib,gzip,io,json,sys,tempfile
ROOT=Path(__file__).resolve().parents[1];sys.path.insert(0,str(ROOT/'tools'))
import publicar_seguimiento_excel_catalogos as release
p=release.engine;package=p.manifest(ROOT/'tools/seguimiento_excel_catalogos_release.json')
fixture=json.loads((ROOT/'tests/fixtures/seguimiento_excel_catalogos_base.json').read_text())
original=gzip.decompress(base64.b64decode(fixture['gzip']));assert p.digest(original)==fixture['sha256']
def deny(fn):
    try:fn()
    except RuntimeError:return
    raise AssertionError('Se esperaba bloqueo')
with tempfile.TemporaryDirectory(prefix='impe-excel-catalogos-test-') as tmp:
    site=Path(tmp)/'public_html';backup=Path(tmp)/'backups';site.mkdir();(site/'index.php').write_text('<?php // fixture')
    dest=site/p.PATHS[0];dest.parent.mkdir(parents=True);dest.write_bytes(original)
    dependencies={}
    for rel,h in p.DEPENDENCIES.items():
        data=(ROOT/rel).read_bytes().replace(b'\r\n',b'\n');assert p.digest(data)==h
        file=site/rel;file.parent.mkdir(parents=True,exist_ok=True);file.write_bytes(data);dependencies[rel]=data
    with contextlib.redirect_stdout(io.StringIO()):
        plan=p.prepare(site,package);assert not backup.exists()
        assert plan[0]['after']==(ROOT/p.PATHS[0]).read_bytes().replace(b'\r\n',b'\n')
        dest.write_bytes(original+b'\n// desconocido');deny(lambda:p.apply(site,backup,package));dest.write_bytes(original)
        file=site/next(iter(dependencies));raw=file.read_bytes();file.write_bytes(raw+b'\n// ajeno')
        deny(lambda:p.apply(site,backup,package));file.write_bytes(raw);assert not backup.exists()
        p.apply(site,backup,package);p.apply(site,backup,package)
        copies=[x for x in backup.iterdir() if x.is_dir()];assert len(copies)==1
        installed=dest.read_bytes();dest.write_bytes(installed+b'\n// posterior')
        deny(lambda:p.restore(site,backup,copies[0]));dest.write_bytes(installed)
        p.restore(site,backup,copies[0]);assert dest.read_bytes()==original
        real=p.atomic;calls=[0]
        def broken(*args):
            calls[0]+=1
            if calls[0]==1:raise OSError('Fallo sintético antes del reemplazo atómico')
            return real(*args)
        p.atomic=broken
        try:
            try:p.apply(site,backup,package)
            except OSError:pass
            else:raise AssertionError('Se esperaba fallo')
        finally:p.atomic=real
        assert dest.read_bytes()==original
        assert all((site/rel).read_bytes()==data for rel,data in dependencies.items())
print('PASS: entrega de un archivo, CHECK sin escrituras, bases y lector protegidos, respaldo, APPLY idempotente, reversión y fallo de publicación; sin SQL.')
