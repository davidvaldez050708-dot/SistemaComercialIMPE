#!/usr/bin/env python3
"""Reproducir publicacion y restauracion de resultado humano, sin produccion."""
import json,os,pathlib,re,subprocess,tempfile
root=pathlib.Path(__file__).resolve().parents[1]
script=root/'tools/publicar_ventas_resultado_sin_end_hostinger.sh'
parts=json.loads(re.search(r"SPECS=json.loads\(r'''(.*?)'''\)",script.read_text(),re.S).group(1))
assert len(parts)==4
with tempfile.TemporaryDirectory() as tmp:
    home=pathlib.Path(tmp);site=home/'public_html';site.mkdir()
    (site/'index.php').write_text('<?php',encoding='utf8')
    private=home/'backups'
    originals={}
    def run(*args):
        env=os.environ.copy()
        env.update(IMPE_CRM_PUBLIC=str(site),IMPE_CRM_BACKUPS=str(private))
        return subprocess.run(['bash',str(script)]+list(args),env=env,
            stdout=subprocess.PIPE,stderr=subprocess.PIPE,universal_newlines=True)
    for i,entry in enumerate(parts):
        name=entry['path'];latest=(root/name).read_text(encoding='utf8')
        previous=latest
        for piece in reversed(entry['patches']):
            assert previous.count(piece['to'])==1
            previous=previous.replace(piece['to'],piece['from'],1)
        end='\r\n' if i%2==0 else '\n'
        original=(previous+'\n/* hostinger local */\n').replace('\n',end).encode()
        path=site/name;path.parent.mkdir(parents=True,exist_ok=True)
        path.write_bytes(original);originals[name]=original
    check=run('--check')
    assert check.returncode==0,(check.stdout,check.stderr)
    assert 'Archivos a modificar: 4' in check.stdout
    assert not private.exists()
    apply=run('--apply')
    assert apply.returncode==0,(apply.stdout,apply.stderr)
    snapshot={name:(site/name).read_bytes() for name in originals}
    assert all(snapshot[name]!=originals[name] for name in originals)
    again=run('--check')
    assert again.returncode==0 and 'Archivos a modificar: 0' in again.stdout
    folder=apply.stdout.split('Respaldo: ')[1].splitlines()[0].strip()
    restore=run('--restore',folder)
    assert restore.returncode==0,(restore.stdout,restore.stderr)
    assert all((site/name).read_bytes()==originals[name] for name in originals)
    item=site/parts[0]['path'];item.write_text('// variante ajena',encoding='utf8')
    invalid=run('--check')
    assert invalid.returncode!=0 and 'CONFLICTO' in invalid.stderr
print('OK: resultado humano con parche de cuatro archivos, CRLF, respaldo y rollback')
