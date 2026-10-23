#!/usr/bin/env python3
"""Publicador de campanas: réplica descartable con LF/CRLF y personalizaciones."""
import os,pathlib,re,json,subprocess,tempfile
root=pathlib.Path(__file__).resolve().parents[1]
installer=root/'tools/publicar_notificaciones_leidas_pendientes_hostinger.sh'
manifest=json.loads(re.search(r"SPEC=json.loads\(r'''(.*?)'''\)",installer.read_text(encoding='utf8'),re.S).group(1))
assert len(manifest['mods'])==6
assert manifest['create']['path']=='prueba_telefonia/api/leer_todas_notificaciones_llamada.php'
assert not any('transferencia' in row['path'] or 'grabacion' in row['path'] for row in manifest['mods'])
for windows in (False,True):
    with tempfile.TemporaryDirectory() as tmp:
        parent=pathlib.Path(tmp);site=parent/'public_html';site.mkdir()
        (site/'index.php').write_text('<?php',encoding='utf8')
        private=parent/'private'
        backup={}
        expected={}
        def norm(text):
            return (text+'\n' if text.endswith('\r') else text).replace('\r\n','\n')
        def encode(text):
            return (text.replace('\n','\r\n') if windows else text).encode('utf8')
        for item in manifest['mods']:
            path=item['path']
            new=(root/path).read_text(encoding='utf8').replace('\r\n','\n')
            old=new
            for from_,to in reversed(item['pairs']):
                from_,to=norm(from_),norm(to)
                assert old.count(to)==1,(path,'new version ambiguous')
                old=old.replace(to,from_,1)
            probe=old
            for from_,to in item['pairs']:
                from_,to=norm(from_),norm(to)
                assert probe.count(from_)==1,(path,'old version ambiguous')
                probe=probe.replace(from_,to,1)
            assert probe==new,(path,'reconstruction mismatch')
            end='\n/* PERSONALIZACION HOSTINGER NO ES DE ESTA ENTREGA */\n'
            filepath=site/path;filepath.parent.mkdir(parents=True,exist_ok=True)
            backup[path]=encode(old+end);expected[path]=encode(new+end)
            filepath.write_bytes(backup[path])
        new_file=site/manifest['create']['path']
        new_file.parent.mkdir(parents=True,exist_ok=True)
        assert not new_file.exists()
        def run(*args):
            env=os.environ.copy()
            env.update(IMPE_CRM_PUBLIC=str(site),IMPE_CRM_BACKUPS=str(private))
            return subprocess.run(['bash',str(installer)]+list(args),env=env,
                stdout=subprocess.PIPE,stderr=subprocess.PIPE,universal_newlines=True)
        check=run('--check')
        assert check.returncode==0 and 'Archivos a modificar: 7' in check.stdout,(check.stdout,check.stderr)
        assert 'OK: lectura masiva de telefonía' in check.stdout,check.stdout
        assert not private.exists(),'--check creó respaldo'
        assert all((site/p).read_bytes()==v for p,v in backup.items())
        applied=run('--apply')
        assert applied.returncode==0,(applied.stdout,applied.stderr)
        assert all((site/p).read_bytes()==v for p,v in expected.items())
        assert new_file.read_bytes()==manifest['create']['content'].encode('utf8')
        again=run('--check')
        assert again.returncode==0 and 'Archivos a modificar: 0' in again.stdout,(again.stdout,again.stderr)
        folder=pathlib.Path(applied.stdout.split('Respaldo: ')[1].splitlines()[0].strip())
        assert folder.is_dir() and private in folder.parents
        one=site/manifest['mods'][0]['path'];one.write_bytes(one.read_bytes()+b'\n// edicion posterior\n')
        fail=run('--restore',str(folder))
        assert fail.returncode!=0 and 'CONFLICTO' in fail.stderr
        one.write_bytes(expected[manifest['mods'][0]['path']])
        restore=run('--restore',str(folder))
        assert restore.returncode==0,(restore.stdout,restore.stderr)
        assert all((site/p).read_bytes()==v for p,v in backup.items())
        assert not new_file.exists()
        broken=site/manifest['mods'][1]['path'];broken.write_bytes(b'<?php // Hostinger otra version')
        refuse=run('--check')
        assert refuse.returncode!=0 and 'CONFLICTO' in refuse.stderr
        assert all((site/p).read_bytes()==v for p,v in backup.items() if p!=manifest['mods'][1]['path'])
print('OK: campanas: 6 parches+endpoint, LF/CRLF, personalizaciones, smoke, rollback y conflictos')
