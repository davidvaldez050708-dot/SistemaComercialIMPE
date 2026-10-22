#!/usr/bin/env python3
"""Parche Hostinger 100->101, copia descartable con customizaciones."""
import os,json,re,subprocess,tempfile,pathlib
root=pathlib.Path(__file__).resolve().parents[1]
pub=root/'tools/publicar_analista_grabaciones_salientes_100_101_hostinger.sh'
m=re.search(r"SPECS=json.loads\(r'''(.*?)'''\)",pub.read_text(encoding='utf8'),re.S)
assert m,'No hay manifiesto.'
spec=json.loads(m.group(1))
assert len(spec['existing'])==6 and len(spec['created'])==2
assert not any('ventas' in row['path'].lower() or 'recepcion_marketing' in row['path']
               for row in spec['existing']+spec['created'])
with tempfile.TemporaryDirectory() as temporary:
    parent=pathlib.Path(temporary)
    site=parent/'public_html';site.mkdir()
    (site/'index.php').write_text('<?php',encoding='utf8')
    helper=site/'app/helpers/ZadarmaPbxIdentifierHelper.php'
    helper.parent.mkdir(parents=True,exist_ok=True)
    helper.write_bytes((root/'app/helpers/ZadarmaPbxIdentifierHelper.php').read_bytes())
    private=parent/'backups'
    original={};updated={}
    def run(*args):
        env=os.environ.copy()
        env.update(IMPE_CRM_PUBLIC=str(site),IMPE_CRM_BACKUPS=str(private))
        return subprocess.run(['bash',str(pub)]+list(args),env=env,
                              text=True,stdout=subprocess.PIPE,stderr=subprocess.PIPE)
    for i,item in enumerate(spec['existing']):
        path=item['path'];current=(root/path).read_text(encoding='utf8').replace('\r\n','\n')
        old=current
        for before,after in reversed(item['pairs']):
            before=(before+'\n' if before.endswith('\r') else before).replace('\r\n','\n')
            after=(after+'\n' if after.endswith('\r') else after).replace('\r\n','\n')
            assert old.count(after)==1,(path,'after no unico')
            old=old.replace(after,before,1)
        test=old
        for before,after in item['pairs']:
            before=(before+'\n' if before.endswith('\r') else before).replace('\r\n','\n')
            after=(after+'\n' if after.endswith('\r') else after).replace('\r\n','\n')
            assert test.count(before)==1,(path,'before no unico')
            test=test.replace(before,after,1)
        assert test==current,path
        suffix='\n/* HOSTINGER PERSONALIZADO AJENO AL PARCHE */\n'
        eol='\r\n' if i%2 else '\n'
        raw=(old+suffix).replace('\n',eol).encode('utf8')
        target=(current+suffix).replace('\n',eol).encode('utf8')
        p=site/path;p.parent.mkdir(parents=True,exist_ok=True);p.write_bytes(raw)
        original[path]=raw;updated[path]=target
    for entry in spec['created']:
        path=site/entry['path'];path.parent.mkdir(parents=True,exist_ok=True)
        assert not path.exists()
    test=run('--check')
    assert test.returncode==0 and 'Archivos a modificar: 8' in test.stdout,(test.stdout,test.stderr)
    assert not private.exists()
    assert all((site/p).read_bytes()==v for p,v in original.items())
    apply=run('--apply')
    assert apply.returncode==0,(apply.stdout,apply.stderr)
    assert all((site/p).read_bytes()==v for p,v in updated.items())
    assert all((site/x['path']).read_bytes()==x['content'].encode('utf8') for x in spec['created'])
    check=run('--check')
    assert check.returncode==0 and 'Archivos a modificar: 0' in check.stdout
    folder=pathlib.Path(apply.stdout.split('Respaldo: ')[1].splitlines()[0].strip())
    assert private in folder.parents and folder.is_dir()
    file=site/spec['existing'][0]['path'];file.write_bytes(file.read_bytes()+b'\n// edicion posterior\n')
    reject=run('--restore',str(folder))
    assert reject.returncode!=0 and 'CONFLICTO' in reject.stderr
    file.write_bytes(updated[spec['existing'][0]['path']])
    restored=run('--restore',str(folder))
    assert restored.returncode==0,(restored.stdout,restored.stderr)
    assert all((site/p).read_bytes()==v for p,v in original.items())
    assert all(not (site/x['path']).exists() for x in spec['created'])
    # Conflictos no tocan siquiera los otros siete archivos.
    unknown=site/spec['existing'][2]['path'];unknown.write_bytes(b'// Archivo personalizado desconocido')
    denied=run('--check')
    assert denied.returncode!=0 and 'CONFLICTO' in denied.stderr
    assert all((site/p).read_bytes()==v for p,v in original.items() if p!=spec['existing'][2]['path'])
print('OK: analista 100->101, 6 parches+2 archivos, CRLF, --check, --apply, --restore y protección personalizada')
