#!/usr/bin/env python3
"""Parches de campanas con cambios Hostinger, CRLF y rollback SHA."""
import json,os,pathlib,re,subprocess,tempfile
root=pathlib.Path(__file__).resolve().parents[1]
script=root/'tools/publicar_telefonia_notificaciones_unificadas_hostinger.sh'
m=re.search(r"SPEC=json.loads\(r'''(.*?)'''\)",script.read_text(encoding='utf8'),re.S)
assert m,'No existe manifiesto'
spec=json.loads(m.group(1))
assert len(spec['changes'])==7
assert spec['newFile']['path']=='prueba_telefonia/api/leer_todas_notificaciones_llamada.php'
assert all('telefonia' in x['path'].lower() or x['path'].endswith(('topbar.php','recordatorios.js','recordatorios.css','ConvocatoriaNotificacionController.php'))
 for x in spec['changes'])
with tempfile.TemporaryDirectory() as temp:
    parent=pathlib.Path(temp);site=parent/'public_html';site.mkdir()
    (site/'index.php').write_text('<?php',encoding='utf8')
    private=parent/'private'
    originals={};expected={}
    for i,item in enumerate(spec['changes']):
        path=item['path']
        latest=(root/path).read_text(encoding='utf8').replace('\r\n','\n')
        before=latest
        def norm(s):
            return (s+'\n' if s.endswith('\r') else s).replace('\r\n','\n')
        for old,new in reversed(item['patches']):
            o,z=norm(old),norm(new)
            assert before.count(z)==1,(path,'No hay fragmento nuevo unico')
            before=before.replace(z,o,1)
        reconstruct=before
        for old,new in item['patches']:
            o,z=norm(old),norm(new)
            assert reconstruct.count(o)==1,(path,'No hay fragmento viejo unico')
            reconstruct=reconstruct.replace(o,z,1)
        assert reconstruct==latest,path
        suffix='\n/* HOSTINGER: CONSERVAR PERSONALIZACIONES AJENAS */\n'
        eol='\r\n' if i%2 else '\n'
        initial=(before+suffix).replace('\n',eol).encode('utf8')
        target=(latest+suffix).replace('\n',eol).encode('utf8')
        f=site/path;f.parent.mkdir(parents=True,exist_ok=True);f.write_bytes(initial)
        originals[path]=initial;expected[path]=target
    new=site/spec['newFile']['path'];new.parent.mkdir(parents=True,exist_ok=True)
    def run(*args):
        env=os.environ.copy();env['IMPE_CRM_PUBLIC']=str(site);env['IMPE_CRM_BACKUPS']=str(private)
        return subprocess.run(['bash',str(script)]+list(args),env=env,
            stdout=subprocess.PIPE,stderr=subprocess.PIPE,text=True)
    check=run('--check')
    assert check.returncode==0 and 'Archivos a modificar: 8' in check.stdout,(check.stdout,check.stderr)
    assert not private.exists() and not new.exists()
    assert all((site/p).read_bytes()==v for p,v in originals.items())
    apply=run('--apply')
    assert apply.returncode==0,(apply.stdout,apply.stderr)
    assert all((site/p).read_bytes()==v for p,v in expected.items())
    assert new.read_bytes()==spec['newFile']['content'].encode('utf8')
    folder=pathlib.Path(apply.stdout.split('Respaldo: ')[1].splitlines()[0])
    assert private in folder.parents
    installed=run('--check')
    assert installed.returncode==0 and 'Archivos a modificar: 0' in installed.stdout
    one=site/spec['changes'][2]['path']
    one.write_bytes(expected[spec['changes'][2]['path']]+b'\n// edit later\n')
    blocked=run('--restore',str(folder))
    assert blocked.returncode!=0 and 'CONFLICTO' in blocked.stderr
    one.write_bytes(expected[spec['changes'][2]['path']])
    restored=run('--restore',str(folder))
    assert restored.returncode==0,(restored.stdout,restored.stderr)
    assert not new.exists()
    assert all((site/p).read_bytes()==v for p,v in originals.items())
    # Publicador nunca reemplaza una personalización nueva que desconoce.
    unknown=site/spec['changes'][0]['path'];unknown.write_bytes(b'<?php // otra version')
    denial=run('--check')
    assert denial.returncode!=0 and 'CONFLICTO' in denial.stderr
    assert all((site/p).read_bytes()==v for p,v in originals.items() if p!=spec['changes'][0]['path'])
print('OK: 7 archivos personalizados, endpoint nuevo, CRLF, --check/--apply/--restore, permisos y conflictos')
