#!/usr/bin/env python3
"""Publicador shell Ventas: --check, --apply, --restore, CRLF y customizaciones."""
import json,os,pathlib,re,subprocess,tempfile
ROOT=pathlib.Path(__file__).resolve().parents[1]
SCRIPT=ROOT/'tools/publicar_ventas_fin_analista_hostinger.sh'
text=SCRIPT.read_text(encoding='utf-8')
pairs=json.loads(re.search(r"PAIRS=json.loads\(r'''(.*?)'''\)",text,re.S).group(1))
assert len(pairs)==4
rel='public/javascript/telefonia_ventas_shell.js'
latest=(ROOT/rel).read_text(encoding='utf-8')
previous=latest
for before,after in reversed(pairs):
    assert previous.count(after)==1, ('falta bloque nuevo',after[:60])
    previous=previous.replace(after,before,1)
assert previous!=latest
def invoke(site,backup,action,extra=None):
    env=os.environ.copy()
    env.update(IMPE_CRM_PUBLIC=str(site),IMPE_CRM_BACKUPS=str(backup))
    return subprocess.run(['bash',str(SCRIPT),action]+(extra or []),
        env=env,stdout=subprocess.PIPE,stderr=subprocess.PIPE,universal_newlines=True)
with tempfile.TemporaryDirectory() as work:
    base=pathlib.Path(work)
    site=base/'public_html';site.mkdir()
    backup=base/'private'
    (site/'index.php').write_text('<?php // replica\n',encoding='utf-8')
    target=site/rel;target.parent.mkdir(parents=True,exist_ok=True)
    suffix='\n/* PERSONALIZACION HOSTINGER CONSERVADA */\n'
    old=(previous+suffix).replace('\n','\r\n').encode('utf-8')
    updated=(latest+suffix).replace('\n','\r\n').encode('utf-8')
    target.write_bytes(old)
    check=invoke(site,backup,'--check')
    assert check.returncode==0 and 'Archivos a modificar: 1' in check.stdout,(check.stdout,check.stderr)
    assert not backup.exists() and target.read_bytes()==old
    apply=invoke(site,backup,'--apply')
    assert apply.returncode==0 and 'OK: corrección aplicada' in apply.stdout,(apply.stdout,apply.stderr)
    assert target.read_bytes()==updated,'Debe conservar personalizacion y CRLF'
    stored=re.search(r'Respaldo: (.*)',apply.stdout)
    assert stored,apply.stdout
    saved=pathlib.Path(stored.group(1).strip())
    assert saved.exists() and backup in saved.parents
    again=invoke(site,backup,'--check')
    assert again.returncode==0 and 'Archivos a modificar: 0' in again.stdout
    concurrent=updated+b'// Cambio posterior\n'
    target.write_bytes(concurrent)
    denied=invoke(site,backup,'--restore',[str(saved)])
    assert denied.returncode!=0 and 'CONFLICTO:' in denied.stderr
    assert target.read_bytes()==concurrent
    target.write_bytes(updated)
    rollback=invoke(site,backup,'--restore',[str(saved)])
    assert rollback.returncode==0,(rollback.stdout,rollback.stderr)
    assert target.read_bytes()==old
    # Un parche aplicado a medias no debe pasar como compatible.
    half=previous.replace(pairs[0][0],pairs[0][1],1).encode()
    target.write_bytes(half)
    denied=invoke(site,backup,'--apply')
    assert denied.returncode!=0 and 'CONFLICTO:' in denied.stderr
    assert target.read_bytes()==half
    target.write_bytes(b'// fuente no reconocida\n')
    denied=invoke(site,backup,'--check')
    assert denied.returncode!=0 and 'CONFLICTO:' in denied.stderr
    assert target.read_bytes()==b'// fuente no reconocida\n'
print('OK: shell Ventas --check/--apply/--restore, CRLF, personalizaciones y bloqueos de conflicto')
