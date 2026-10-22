#!/usr/bin/env python3
"""Valida publicador de dos archivos, CRLF, personalizaciones, rollback y conflictos."""
import json,os,pathlib,re,subprocess,tempfile
root=pathlib.Path(__file__).resolve().parents[1]
publisher=root/'tools/publicar_ventas_fin_sip_transferido_100_150_hostinger.sh'
manifest=json.loads(re.search(r"SPECS=json.loads\(r'''(.*?)'''\)",publisher.read_text(),re.S).group(1))
assert [s['path'] for s in manifest]==['app/views/telefonia/host.php','public/javascript/telefonia_host.js','public/javascript/telefonia_transferencia_runtime.js']
with tempfile.TemporaryDirectory() as td:
    base=pathlib.Path(td);site=base/'public_html';site.mkdir();backup=base/'private'
    (site/'index.php').write_text('<?php',encoding='utf-8')
    originals={};expected={}
    def run(*args):
        env=os.environ.copy()
        env.update(IMPE_CRM_PUBLIC=str(site),IMPE_CRM_BACKUPS=str(backup))
        return subprocess.run(['bash',str(publisher)]+list(args),
            env=env,stdout=subprocess.PIPE,stderr=subprocess.PIPE,universal_newlines=True)
    for n,spec in enumerate(manifest):
        path=spec['path'];latest=(root/path).read_text(encoding='utf-8')
        earlier=latest
        for old,new in reversed(spec['pairs']):
            assert earlier.count(new)==1,(path,'expect unique')
            earlier=earlier.replace(new,old,1)
        again=earlier
        for old,new in spec['pairs']:
            assert again.count(old)==1,(path,'replay')
            again=again.replace(old,new,1)
        assert again==latest,path
        suffix='\n/* PRODUCCION: PERSONALIZACION AJENA NO MODIFICAR */\n'
        eol='\r\n' if n%2==0 else '\n'
        earlier=(earlier+suffix).replace('\n',eol).encode('utf8')
        target=(latest+suffix).replace('\n',eol).encode('utf8')
        file=site/path;file.parent.mkdir(parents=True,exist_ok=True);file.write_bytes(earlier)
        originals[path]=earlier;expected[path]=target
    check=run('--check')
    assert check.returncode==0 and 'Archivos a modificar: 3' in check.stdout,(check.stdout,check.stderr)
    assert not backup.exists()
    apply=run('--apply')
    assert apply.returncode==0,(apply.stdout,apply.stderr)
    assert all((site/p).read_bytes()==s for p,s in expected.items())
    again=run('--check')
    assert again.returncode==0 and 'Archivos a modificar: 0' in again.stdout
    folder=pathlib.Path(apply.stdout.split('Respaldo: ')[1].splitlines()[0].strip())
    assert backup in folder.parents
    file=site/manifest[0]['path'];file.write_bytes(file.read_bytes()+b'// alteracion posterior')
    rejected=run('--restore',str(folder))
    assert rejected.returncode!=0 and 'CONFLICTO' in rejected.stderr
    file.write_bytes(expected[manifest[0]['path']])
    restored=run('--restore',str(folder))
    assert restored.returncode==0,(restored.stdout,restored.stderr)
    assert all((site/p).read_bytes()==s for p,s in originals.items())
    first=site/manifest[1]['path'];first.write_bytes(b'// js desconocido')
    rejected=run('--check')
    assert rejected.returncode!=0 and 'CONFLICTO' in rejected.stderr
    assert (site/manifest[0]['path']).read_bytes()==originals[manifest[0]['path']]
print('OK: fin SIP propio 100 a 150 publicador de 3 archivos, check/apply/restore, CRLF, variantes, rollback y backup')
