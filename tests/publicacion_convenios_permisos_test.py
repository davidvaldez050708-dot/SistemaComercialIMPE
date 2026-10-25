import base64, gzip, hashlib, json, os, pathlib, shutil, subprocess, sys, tempfile
R = pathlib.Path(__file__).resolve().parents[1]
spec = json.loads((R/'tools/convenios_publicacion_permisos_release.json').read_text(encoding='utf8'))
def unpack(value): return gzip.decompress(base64.b64decode(value))
with tempfile.TemporaryDirectory() as tmp:
    root=pathlib.Path(tmp)/'sitio';root.mkdir();(root/'index.php').write_text('<?php')
    backups=pathlib.Path(tmp)/'respaldos'
    before={}
    for row in spec['files']:
        p=root/row['path'];v=row['variants'][0];new=unpack(v['after'])
        assert hashlib.sha256(new).hexdigest()==v['after_sha']
        assert (R/row['path']).read_bytes().replace(b'\r\n',b'\n')==new, row['path']+' paquete obsoleto'
        before[row['path']]=unpack(v['before']).replace(b'\n',b'\r\n') if v['before'] else None
        if before[row['path']] is not None:p.parent.mkdir(parents=True,exist_ok=True);p.write_bytes(before[row['path']])
    # Un archivo ajeno (incluyendo telefonía) nunca debe tocarse.
    other=root/'public/javascript/telefonia_host.js';other.parent.mkdir(parents=True,exist_ok=True);other.write_bytes(b'custom-phone')
    command=[sys.executable,str(R/'tools/publicar_convenios_publicacion_permisos.py'),'--site',str(root),'--backup-root',str(backups)]
    def run(*args,ok=True):
        result=subprocess.run(command+list(args),capture_output=True,text=True)
        assert (result.returncode==0)==ok,result.stdout+result.stderr
        return result.stdout
    def unchanged():
        for name,data in before.items():
            p=root/name;assert (p.read_bytes() if p.exists() else None)==data
    run('--check');unchanged();assert not backups.exists()
    target=root/'app/controllers/ConvocatoriaController.php';target.write_bytes(target.read_bytes()+b'\n//custom')
    run('--apply',ok=False)
    assert target.read_bytes()==before['app/controllers/ConvocatoriaController.php']+b'\n//custom', 'Conflicto debe conservar la personalización'
    target.write_bytes(before['app/controllers/ConvocatoriaController.php']);run('--apply')
    output=run('--check');assert output.count('ACTUALIZADO:')==3
    folder=next(backups.iterdir());run('--apply');assert len(list(backups.iterdir()))==1
    target.write_bytes(target.read_bytes()+b'\n//later');run('--restore',str(folder),ok=False)
    v=next(x['variants'][0] for x in spec['files'] if x['path']=='app/controllers/ConvocatoriaController.php');target.write_bytes(unpack(v['after']).replace(b'\n',b'\r\n'))
    run('--restore',str(folder));unchanged();assert other.read_bytes()==b'custom-phone'
print('Publicación: tres variantes verificadas, CRLF, conflictos, idempotencia y restauración exacta OK')
