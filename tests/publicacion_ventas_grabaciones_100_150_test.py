#!/usr/bin/env python3
"""Sin producción: instala audio 100/150 sobre Hostinger modificado y restaura."""
import json,os,pathlib,re,subprocess,tempfile
root=pathlib.Path(__file__).resolve().parents[1]
script=root/'tools/publicar_ventas_grabaciones_100_150_hostinger.sh'
m=re.search(r"SPECS=json.loads\(r'''(.*?)'''\)",script.read_text(encoding='utf8'),re.S)
assert m,'Manifiesto ausente'
spec=json.loads(m.group(1))
assert len(spec['mods'])==5
assert spec['newService']['path']=='app/services/TelefoniaGrabacionesTransferenciaVentasService.php'
assert len(set(row['path'] for row in spec['mods']))==5
with tempfile.TemporaryDirectory() as td:
    base=pathlib.Path(td)
    site=base/'public_html';site.mkdir()
    (site/'index.php').write_text('<?php',encoding='utf8')
    helper=site/'app/helpers/ZadarmaPbxIdentifierHelper.php'
    helper.parent.mkdir(parents=True,exist_ok=True)
    helper.write_bytes((root/'app/helpers/ZadarmaPbxIdentifierHelper.php').read_bytes())
    private=base/'private'
    originals={};targets={}
    def run(*args):
        env=os.environ.copy()
        env.update(IMPE_CRM_PUBLIC=str(site),IMPE_CRM_BACKUPS=str(private))
        return subprocess.run(['bash',str(script)]+list(args),env=env,
            stdout=subprocess.PIPE,stderr=subprocess.PIPE,universal_newlines=True)
    for i,entry in enumerate(spec['mods']):
        latest=(root/entry['path']).read_text(encoding='utf8')
        original=latest
        for old,new in reversed(entry['pairs']):
            old,new=old.replace('\r\n','\n'),new.replace('\r\n','\n')
            assert original.count(new)==1,(entry['path'],'ambiguous target')
            original=original.replace(new,old,1)
        check=original
        for old,new in entry['pairs']:
            old,new=old.replace('\r\n','\n'),new.replace('\r\n','\n')
            assert check.count(old)==1,(entry['path'],'ambiguous source')
            check=check.replace(old,new,1)
        assert check==latest,entry['path']
        # Reproducir específicamente CRLF del archivo real de Recepción.
        eol='\r\n' if i==2 or i%2 else '\n'
        suffix='\n/* HOSTINGER: PERSONALIZACION NO REEMPLAZAR */\n'
        original=(original+suffix).replace('\n',eol).encode('utf8')
        expected=(latest+suffix).replace('\n',eol).encode('utf8')
        file=site/entry['path'];file.parent.mkdir(parents=True,exist_ok=True)
        file.write_bytes(original)
        originals[entry['path']]=original;targets[entry['path']]=expected
    new=spec['newService']['path']
    checked=run('--check')
    assert checked.returncode==0 and 'Archivos a modificar: 6' in checked.stdout,(checked.stdout,checked.stderr)
    assert not private.exists()
    assert all((site/k).read_bytes()==v for k,v in originals.items())
    assert not (site/new).exists()
    applied=run('--apply')
    assert applied.returncode==0,(applied.stdout,applied.stderr)
    assert all((site/k).read_bytes()==v for k,v in targets.items())
    assert (site/new).read_bytes()==spec['newService']['content'].encode('utf8')
    folder=pathlib.Path(applied.stdout.split('Respaldo: ')[1].splitlines()[0].strip())
    assert private in folder.parents
    again=run('--check')
    assert again.returncode==0 and 'Archivos a modificar: 0' in again.stdout
    changed=site/spec['mods'][1]['path']
    changed.write_bytes(changed.read_bytes()+b'// cambio posterior')
    denied=run('--restore',str(folder))
    assert denied.returncode!=0 and 'CONFLICTO' in denied.stderr
    changed.write_bytes(targets[spec['mods'][1]['path']])
    restored=run('--restore',str(folder))
    assert restored.returncode==0,(restored.stdout,restored.stderr)
    assert not (site/new).exists(),'Servicio nuevo no debe quedar tras restauración'
    assert all((site/k).read_bytes()==v for k,v in originals.items())
    # Si ya existiera el servicio, la restauración debe preservarlo.
    (site/new).write_bytes(spec['newService']['content'].encode('utf8'))
    installed=run('--apply')
    assert installed.returncode==0,(installed.stdout,installed.stderr)
    second=pathlib.Path(installed.stdout.split('Respaldo: ')[1].splitlines()[0].strip())
    assert run('--restore',str(second)).returncode==0
    assert (site/new).exists(),'No borrar servicio preexistente en restore'
    altered=site/spec['mods'][2]['path'];altered.write_bytes(b'// Archivo ajeno')
    rejected=run('--check')
    assert rejected.returncode!=0 and 'CONFLICTO' in rejected.stderr
    assert all((site/k).read_bytes()==v for k,v in originals.items() if k!=spec['mods'][2]['path'])
print('OK: dos grabaciones, 5 parches y 1 servicio; CRLF, respaldo, idempotencia, restauración y variantes ajenas')
