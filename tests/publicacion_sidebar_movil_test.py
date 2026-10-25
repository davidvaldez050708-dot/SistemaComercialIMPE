import base64, gzip, hashlib, json, pathlib, subprocess, sys, tempfile

ROOT=pathlib.Path(__file__).resolve().parents[1]
spec=json.loads((ROOT/'tools/sidebar_movil_perfil_release.json').read_text(encoding='utf8'))
def unpack(value): return gzip.decompress(base64.b64decode(value))
for crlf in [False, True]:
    with tempfile.TemporaryDirectory() as tmp:
        site=pathlib.Path(tmp)/'sitio';site.mkdir();(site/'index.php').write_text('<?php')
        backups=pathlib.Path(tmp)/'respaldos'
        before={}
        for row in spec['files']:
            v=row['variants'][0];new=unpack(v['after']);old=unpack(v['before'])
            assert hashlib.sha256(new).hexdigest()==v['after_sha']
            assert (ROOT/row['path']).read_bytes().replace(b'\r\n',b'\n')==new
            p=site/row['path'];p.parent.mkdir(parents=True,exist_ok=True)
            before[row['path']]=old.replace(b'\n',b'\r\n') if crlf else old
            p.write_bytes(before[row['path']])
        # Las personalizaciones responsive y otros módulos quedan intactos.
        responsive=site/'public/css/responsive_sistema.css';responsive.write_bytes(b'custom responsive')
        cmd=[sys.executable,str(ROOT/'tools/publicar_sidebar_movil_perfil.py'),'--site',str(site),'--backup-root',str(backups)]
        def run(*args,ok=True):
            r=subprocess.run(cmd+list(args),capture_output=True,text=True)
            assert (r.returncode==0)==ok,r.stdout+r.stderr
            return r.stdout
        def unchanged():
            for name,data in before.items(): assert (site/name).read_bytes()==data
            assert responsive.read_bytes()==b'custom responsive'
        run('--check');unchanged();assert not backups.exists()
        css=site/'public/css/global.css';css.write_bytes(css.read_bytes()+b'\n/* custom */')
        run('--apply',ok=False)
        assert (site/'app/views/layout/sidebar.php').read_bytes()==before['app/views/layout/sidebar.php']
        assert not backups.exists()
        css.write_bytes(before['public/css/global.css'])
        run('--apply');assert run('--check').count('ACTUALIZADO:')==2
        folder=next(backups.iterdir());run('--apply');assert len(list(backups.iterdir()))==1
        current=css.read_bytes();css.write_bytes(current+b'\n/* later */')
        run('--restore',str(folder),ok=False)
        assert css.read_bytes()==current+b'\n/* later */'
        css.write_bytes(current)
        run('--restore',str(folder));unchanged()
print('Publicador: LF/CRLF, check sin escritura, conflictos, respaldo, idempotencia y restauración exacta OK')
