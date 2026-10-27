import base64, gzip, hashlib, json, pathlib, subprocess, sys, tempfile

R=pathlib.Path(__file__).resolve().parents[1]
spec=json.loads((R/'tools/ventas_observaciones_release.json').read_text(encoding='utf8'))
assert len(spec['files'])==6
unpack=lambda value:gzip.decompress(base64.b64decode(value))
for crlf in (False,True):
    with tempfile.TemporaryDirectory() as folder:
        private=pathlib.Path(folder);site=private/'public_html';site.mkdir();(site/'index.php').write_bytes(b'<?php')
        before={};after={};deps={}
        for row in spec['files']:
            v=row['variants'][0];old=unpack(v['before']) if v['before'] else None;new=unpack(v['after'])
            assert hashlib.sha256(new).hexdigest()==v['after_sha']
            assert (R/row['path']).read_bytes().replace(b'\r\n',b'\n')==new
            before[row['path']]=old.replace(b'\n',b'\r\n') if crlf and old is not None else old
            after[row['path']]=new.replace(b'\n',b'\r\n') if crlf and old is not None else new
            target=site/row['path'];target.parent.mkdir(parents=True,exist_ok=True)
            if old is not None:target.write_bytes(before[row['path']])
        for d in spec['dependencies']:
            raw=(R/d['path']).read_bytes().replace(b'\r\n',b'\n');assert hashlib.sha256(raw).hexdigest()==d['sha']
            raw=raw.replace(b'\n',b'\r\n') if crlf else raw;deps[d['path']]=raw
            target=site/d['path'];target.parent.mkdir(parents=True,exist_ok=True);target.write_bytes(raw)
        backups=private/'respaldos';command=[sys.executable,str(R/'tools/publicar_ventas_observaciones.py'),'--site',str(site),'--backup-root',str(backups)]

        def run(*args,ok=True):
            r=subprocess.run(command+list(args),capture_output=True,text=True)
            assert (r.returncode==0)==ok,r.stdout+r.stderr
            return r.stdout

        def unchanged():
            for p,data in before.items():assert ((site/p).read_bytes() if (site/p).exists() else None)==data,p
            for p,data in deps.items():assert (site/p).read_bytes()==data,p

        run('--check');unchanged();assert not backups.exists()
        target=site/spec['files'][-1]['path'];old=target.read_bytes();target.write_bytes(old+b'\n// personalizacion posterior')
        run('--apply',ok=False);target.write_bytes(old);unchanged();assert not backups.exists()
        dep=site/spec['dependencies'][0]['path'];original=dep.read_bytes();dep.write_bytes(original+b'\n// identidad diferente')
        run('--apply',ok=False);dep.write_bytes(original);unchanged();assert not backups.exists()
        run('--apply');assert all((site/p).read_bytes()==b for p,b in after.items())
        backup=next(backups.iterdir());assert run('--check').count('ACTUALIZADO:')==6
        run('--apply');assert len(list(backups.iterdir()))==1
        current=target.read_bytes();target.write_bytes(current+b'\n// edicion posterior');run('--restore',str(backup),ok=False);target.write_bytes(current)
        run('--restore',str(backup));unchanged()
print('Publicación: seis archivos, versiones exactas, LF/CRLF, dependencias intactas, respaldo, reintentos y restauración sin pérdida de datos OK')
