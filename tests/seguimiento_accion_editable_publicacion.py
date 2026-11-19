"""Entrega exacta y reversible en un sitio sintético; sin conexión SQL/SIP."""
import contextlib
import io
from pathlib import Path
import subprocess
import sys
import tempfile
ROOT=Path(__file__).resolve().parents[1]
sys.path.insert(0,str(ROOT/'tools'))
import publicar_analista_accion_editable as release
p=release.engine
package=p.manifest(ROOT/'tools/analista_accion_editable_release.json')
def deny(fn):
    try:fn()
    except RuntimeError:return
    raise AssertionError('Se esperaba bloqueo')
with tempfile.TemporaryDirectory(prefix='impe-tonos-test-') as tmp:
    site=Path(tmp)/'public_html';root=Path(tmp)/'backups'
    site.mkdir();(site/'index.php').write_text('<?php // fixture')
    originals={}
    for rel in p.PATHS:
        data=subprocess.check_output(['git','show','dfb170bc498b9a61e14e24c4cb3111be927d0289:'+rel],cwd=ROOT)
        originals[rel]=data;dest=site/rel;dest.parent.mkdir(parents=True,exist_ok=True);dest.write_bytes(data)
    with contextlib.redirect_stdout(io.StringIO()):
        rows=p.prepare(site,package);assert not root.exists()
        for row in rows:
            assert row['before']==originals[row['name']]
            assert row['after']==(ROOT/row['name']).read_bytes().replace(b'\r\n',b'\n')
        file=site/p.PATHS[0];raw=file.read_bytes();file.write_bytes(raw+b'\n// custom')
        deny(lambda:p.apply(site,root,package));file.write_bytes(raw);assert not root.exists()
        p.apply(site,root,package);backup=next(x for x in root.iterdir() if x.is_dir())
        p.apply(site,root,package);assert len([x for x in root.iterdir() if x.is_dir()])==1
        file=site/p.PATHS[0];raw=file.read_bytes();file.write_bytes(raw+b'\n// posterior')
        deny(lambda:p.restore(site,root,backup));file.write_bytes(raw)
        p.restore(site,root,backup)
        assert all((site/rel).read_bytes()==data for rel,data in originals.items())
        real=p.atomic;calls=[0]
        def broken(*args):
            calls[0]+=1
            if calls[0]==2:raise OSError('Fallo sintético de publicación')
            return real(*args)
        p.atomic=broken
        try:
            try:p.apply(site,root,package)
            except OSError:pass
            else:raise AssertionError('Se esperaba fallo parcial')
        finally:p.atomic=real
        assert all((site/rel).read_bytes()==data for rel,data in originals.items())
print('CHECK_OK publicación: bases Hostinger exactas, CHECK sin escrituras, rechazo de personalizaciones, respaldo, APPLY idempotente, reversión y recuperación de fallo parcial; sin SQL ni SIP.')
