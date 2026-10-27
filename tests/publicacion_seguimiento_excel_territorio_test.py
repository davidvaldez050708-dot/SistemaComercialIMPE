import base64, gzip, hashlib, json, pathlib, subprocess, sys, tempfile

R=pathlib.Path(__file__).resolve().parents[1]
spec=json.loads((R/'tools/seguimiento_excel_territorio_release.json').read_text(encoding='utf8'))
assert [r['path'] for r in spec['files']]==['public/javascript/seguimiento_importacion_excel.js','app/views/seguimiento_vinculacion/index.php']
assert [r['variants'][0]['before_sha'] for r in spec['files']]==[
    'ecfb3e1e0857be94b251f33c61c72f2ab36bc7b2b759cff953d23be6e2647978',
    '3bf405a7e1f514c38d62b45c2a87f80120b7acdf4304b7f05e5e55a066033fa1']
unpack=lambda b:gzip.decompress(base64.b64decode(b))
for crlf in (False,True):
    with tempfile.TemporaryDirectory() as folder:
        private=pathlib.Path(folder);site=private/'public_html';site.mkdir();(site/'index.php').write_bytes(b'<?php')
        before={};after={}
        for row in spec['files']:
            v=row['variants'][0];old=unpack(v['before']);new=unpack(v['after'])
            assert hashlib.sha256(old).hexdigest()==v['before_sha']
            assert hashlib.sha256(new).hexdigest()==v['after_sha']
            assert (R/row['path']).read_bytes().replace(b'\r\n',b'\n')==new
            before[row['path']]=old.replace(b'\n',b'\r\n') if crlf else old
            after[row['path']]=new.replace(b'\n',b'\r\n') if crlf else new
            target=site/row['path'];target.parent.mkdir(parents=True,exist_ok=True);target.write_bytes(before[row['path']])
        sentinel=site/'app/controllers/SeguimientoVinculacionController.php';sentinel.parent.mkdir(parents=True,exist_ok=True);sentinel.write_bytes(b'<?php /* controlador personalizado intacto */')
        backups=private/'respaldos';command=[sys.executable,str(R/'tools/publicar_seguimiento_excel_territorio.py'),'--site',str(site),'--backup-root',str(backups)]
        def run(*args,ok=True):
            r=subprocess.run(command+list(args),capture_output=True,text=True)
            assert (r.returncode==0)==ok,r.stdout+r.stderr
            return r.stdout
        def unchanged():
            assert all((site/p).read_bytes()==data for p,data in before.items())
            assert sentinel.read_bytes()==b'<?php /* controlador personalizado intacto */'
        run('--check');unchanged();assert not backups.exists()
        target=site/spec['files'][-1]['path'];original=target.read_bytes();target.write_bytes(original+b'\nPersonalizacion adicional')
        run('--apply',ok=False);target.write_bytes(original);unchanged();assert not backups.exists()
        run('--apply');assert all((site/p).read_bytes()==data for p,data in after.items())
        backup=next(backups.iterdir());assert run('--check').count('ACTUALIZADO:')==2
        run('--apply');assert len(list(backups.iterdir()))==1
        current=target.read_bytes();target.write_bytes(current+b'\nEdicion posterior');run('--restore',str(backup),ok=False);target.write_bytes(current)
        run('--restore',str(backup));unchanged()
print('Publicación Excel: dos hashes de Hostinger, LF/CRLF, check sin escrituras, rechazo de versiones, respaldo y restauración exacta; controlador intacto OK')
