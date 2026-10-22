#!/usr/bin/env python3
"""Prueba en replicas: 5 archivos, personalizaciones, CRLF, idempotencia y rollback."""
import json,os,pathlib,re,subprocess,tempfile
root=pathlib.Path(__file__).resolve().parents[1]
script=root/'tools/publicar_ventas_disponibilidad_resultado_hostinger.sh'
spec=json.loads(re.search(r"SPEC=json.loads\(r'''(.*?)'''\)",script.read_text(),re.S).group(1))
assert len(spec)==5
with tempfile.TemporaryDirectory() as tmp:
    base=pathlib.Path(tmp)
    site=base/'public_html';site.mkdir()
    (site/'index.php').write_text('<?php',encoding='utf8')
    backup=base/'backup'
    expected={};previous={}
    def call(*args):
        env=os.environ.copy();env.update(IMPE_CRM_PUBLIC=str(site),IMPE_CRM_BACKUPS=str(backup))
        return subprocess.run(['bash',str(script)]+list(args),env=env,
            stdout=subprocess.PIPE,stderr=subprocess.PIPE,universal_newlines=True)
    for i,item in enumerate(spec):
        name=item['path']
        latest=(root/name).read_text(encoding='utf8')
        older=latest
        for old,new in reversed(item['patches']):
            assert older.count(new)==1,(name,'destino ambiguo')
            older=older.replace(new,old,1)
        assert older!=latest
        suffix='\n/* HOSTINGER PERSONALIZACION AJENA */\n'
        eol='\r\n' if i%2 else '\n'
        initial=(older+suffix).replace('\n',eol).encode('utf8')
        new=(latest+suffix).replace('\n',eol).encode('utf8')
        p=site/name;p.parent.mkdir(parents=True,exist_ok=True);p.write_bytes(initial)
        expected[name]=new;previous[name]=initial
    chk=call('--check')
    assert chk.returncode==0 and 'Archivos a modificar: 5' in chk.stdout,(chk.stdout,chk.stderr)
    assert not backup.exists() and all((site/n).read_bytes()==v for n,v in previous.items())
    done=call('--apply')
    assert done.returncode==0,(done.stdout,done.stderr)
    assert all((site/n).read_bytes()==v for n,v in expected.items())
    folder=pathlib.Path(done.stdout.split('Respaldo: ')[1].splitlines()[0].strip())
    assert backup in folder.parents and folder.is_dir()
    repeated=call('--check')
    assert repeated.returncode==0 and 'Archivos a modificar: 0' in repeated.stdout
    altered=site/spec[2]['path'];altered.write_bytes(altered.read_bytes()+b'\n// edicion nueva\n')
    reject=call('--restore',str(folder))
    assert reject.returncode!=0 and 'CONFLICTO' in reject.stderr
    altered.write_bytes(expected[spec[2]['path']])
    restore=call('--restore',str(folder))
    assert restore.returncode==0,(restore.stdout,restore.stderr)
    assert all((site/n).read_bytes()==v for n,v in previous.items())
    unknown=site/spec[3]['path'];unknown.write_bytes(b'// variante desconocida')
    rejected=call('--apply')
    assert rejected.returncode!=0 and 'CONFLICTO' in rejected.stderr
    assert all((site/n).read_bytes()==v for n,v in previous.items() if n!=spec[3]['path'])
print('OK: disponibilidad/resultado check, apply, restore, CRLF, version ajena y respaldo seguro')
