#!/usr/bin/env python3
"""Hostinger: parches PBX 100→150 en réplica aislada, sin modificar producción."""
import json,os,pathlib,re,subprocess,tempfile
ROOT=pathlib.Path(__file__).resolve().parents[1]
SCRIPT=ROOT/'tools/publicar_ventas_pbx_transferencia_100_150_hostinger.sh'
specs=json.loads(re.search(r"SPECS=json.loads\(r'''(.*?)'''\)",SCRIPT.read_text(),re.S).group(1))
assert len(specs)==2
def invoke(site,backup,*args):
    env=os.environ.copy();env.update(IMPE_CRM_PUBLIC=str(site),IMPE_CRM_BACKUPS=str(backup))
    return subprocess.run(['bash',str(SCRIPT)]+list(args),env=env,
        stdout=subprocess.PIPE,stderr=subprocess.PIPE,universal_newlines=True)
with tempfile.TemporaryDirectory() as td:
    base=pathlib.Path(td);site=base/'public_html';site.mkdir()
    backup=base/'backup_private'
    (site/'index.php').write_text('<?php',encoding='utf-8')
    originals={};applied={}
    for n,item in enumerate(specs):
        rel=item['path'];newest=(ROOT/rel).read_text(encoding='utf-8')
        assert newest.count(item['to'])==1 and newest.count(item['from'])==0
        previous=newest.replace(item['to'],item['from'],1)
        suffix='\n/* PERSONALIZACION HOSTINGER CONSERVAR */\n'
        eol='\r\n' if n==0 else '\n'
        old=(previous+suffix).replace('\n',eol).encode('utf-8')
        new=(newest+suffix).replace('\n',eol).encode('utf-8')
        f=site/rel;f.parent.mkdir(parents=True,exist_ok=True);f.write_bytes(old)
        originals[rel]=old;applied[rel]=new
    check=invoke(site,backup,'--check')
    assert check.returncode==0 and 'Archivos a modificar: 2' in check.stdout,(check.stdout,check.stderr)
    assert not backup.exists()
    assert all((site/k).read_bytes()==v for k,v in originals.items())
    action=invoke(site,backup,'--apply')
    assert action.returncode==0,(action.stdout,action.stderr)
    folder=pathlib.Path(re.search(r'Respaldo: (.*)',action.stdout).group(1).strip())
    assert folder.is_dir() and backup in folder.parents
    assert all((site/k).read_bytes()==v for k,v in applied.items()),'No borrar customizaciones'
    repeat=invoke(site,backup,'--check')
    assert repeat.returncode==0 and 'Archivos a modificar: 0' in repeat.stdout
    corrupted=site/specs[1]['path']
    corrupted.write_bytes(corrupted.read_bytes()+b'\n// cambio ajeno\n')
    denied=invoke(site,backup,'--restore',str(folder))
    assert denied.returncode!=0 and 'CONFLICTO' in denied.stderr
    assert (site/specs[0]['path']).read_bytes()==applied[specs[0]['path']]
    corrupted.write_bytes(applied[specs[1]['path']])
    restored=invoke(site,backup,'--restore',str(folder))
    assert restored.returncode==0,(restored.stdout,restored.stderr)
    assert all((site/k).read_bytes()==v for k,v in originals.items())
    corrupted.write_bytes(b'<?php // fichero desconocido\n')
    denied=invoke(site,backup,'--check')
    assert denied.returncode!=0 and 'CONFLICTO' in denied.stderr
    assert all((site/k).read_bytes()==v for k,v in originals.items() if k!=specs[1]['path'])
print('OK: PBX 100→150 --check/--apply/--restore, CRLF, personalizaciones, conflicto y respaldo')
