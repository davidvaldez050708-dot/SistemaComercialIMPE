"""Prueba aislada del parche Hostinger: detecta personalizaciones, instala y restaura."""
import base64
import gzip
import json
import os
from pathlib import Path
import subprocess
import sys
import tempfile

ROOT=Path(__file__).resolve().parents[1]
SCRIPT=ROOT/'tools/publicar_analista_modal_compartido.py'
REG='public/javascript/seguimiento_llamada_registro_obligatorio.js'
SHELL='public/javascript/telefonia_shell.js'
PHONE='public/javascript/telefonia_persistente.js'
SNAPSHOT='d5e70163c2999cdb68d73396079f73ef3056e2aa'
package=json.loads((ROOT/'tools/analista_salientes_release.json').read_text(encoding='utf-8'))
old_reg=gzip.decompress(base64.b64decode(next(row for row in package['files'] if row['path']==REG)['variants'][0]['target']))
git=lambda path:subprocess.check_output(['git','show',SNAPSHOT+':'+path],cwd=str(ROOT))
old_shell=git(SHELL)+b'\n// PERSONALIZACION LOCAL: se conserva\n'
old_phone=git(PHONE)+b'\n// PERSONALIZACION LOCAL: se conserva\n'
target=(ROOT/REG).read_bytes()

with tempfile.TemporaryDirectory(prefix='impe-modal-compartido-test-') as temp:
    root=Path(temp);site=root/'site';site.mkdir()
    (site/'index.php').write_text('<?php // fixture',encoding='utf-8')
    backup=root/'private'
    originals={REG:old_reg,SHELL:old_shell,PHONE:old_phone}
    for name,data in originals.items():
        path=site/name;path.parent.mkdir(parents=True,exist_ok=True);path.write_bytes(data)
    def snapshot():
        return {p.relative_to(site).as_posix():p.read_bytes() for p in site.rglob('*') if p.is_file()}
    def run(*args,**kw):
        result=subprocess.run([sys.executable,str(SCRIPT),'--site',str(site),'--backup-root',str(backup),'--target',str(ROOT/REG)]+list(args),
            stdout=subprocess.PIPE,stderr=subprocess.PIPE)
        if bool(result.returncode==0)!=bool(kw.get('ok',True)):
            raise AssertionError((result.stdout+result.stderr).decode('utf-8',errors='replace'))
        return (result.stdout+result.stderr).decode('utf-8',errors='replace')
    before=snapshot()
    assert 'CHECK OK' in run('--check')
    assert snapshot()==before and not backup.exists(), 'CHECK no debe crear respaldo'
    reg=site/REG
    reg.write_bytes(old_reg+b'// variante no reconocida')
    assert 'VERSIÓN DIFERENTE' in run('--check',ok=False)
    assert snapshot()[SHELL]==before[SHELL] and not backup.exists()
    reg.write_bytes(old_reg)
    phone=site/PHONE
    phone.write_bytes(old_phone.replace(b'manualOutgoingInShell',b'otroNombreDeVariable'))
    assert 'CONFLICTO' in run('--check',ok=False)
    phone.write_bytes(old_phone)
    assert 'APLICADO' in run('--apply')
    assert reg.read_bytes()==target
    assert b'PERSONALIZACION LOCAL: se conserva' in (site/SHELL).read_bytes()
    assert b'PERSONALIZACION LOCAL: se conserva' in phone.read_bytes()
    assert (site/SHELL).read_bytes()!=old_shell and phone.read_bytes()!=old_phone
    current=snapshot()
    archive=next(p for p in backup.iterdir() if p.is_dir())
    assert 'YA INSTALADO' in run('--apply')
    assert len([p for p in backup.iterdir() if p.is_dir()])==1,'idempotencia'
    phone.write_bytes(phone.read_bytes()+b'// cambio posterior')
    assert 'cambiada' in run('--restore',str(archive),ok=False)
    phone.write_bytes(current[PHONE])
    assert 'RESTAURADO' in run('--restore',str(archive))
    assert snapshot()==before,'restaurar la versión personalizada exacta'
# Variante real Hostinger reconocida sólo con huella SHA-256 exacta.
# La prueba construye un fragmento sintético y simula exclusivamente esa huella;
# el chequeo productivo nunca admite un hash arbitrario.
import importlib.util
spec=importlib.util.spec_from_file_location('publisher', str(SCRIPT))
module=importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)
hostinger_patch=next(row for row in module.PATCHES if row['path']==PHONE)
hostinger_before=module.HOSTINGER_PERSISTENT_BEFORE.encode('utf-8')
hostinger_after=module.HOSTINGER_PERSISTENT_AFTER.encode('utf-8')
fixture=b'// Prologo de una personalizacion local\n'+hostinger_before+b'\n// Epilogo sin modificar\n'
previous_sha=module.sha
try:
    module.sha=lambda data: module.HOSTINGER_PERSISTENT_SHA256
    fixed=module.patched(fixture,hostinger_patch['changes'],PHONE)
    assert fixed==fixture.replace(hostinger_before,hostinger_after)
    assert fixed.endswith(b'// Epilogo sin modificar\n')
finally:
    module.sha=previous_sha
assert module.patched(fixed,hostinger_patch['changes'],PHONE)==fixed,'Idempotencia del parche local'
try:
    module.patched(fixture,hostinger_patch['changes'],PHONE)
    raise AssertionError('No aceptar un archivo desconocido sin la huella exacta')
except RuntimeError as exc:
    assert 'CONFLICTO' in str(exc)

print('PASS parche Hostinger: check sin escritura, variantes desconocidas bloqueadas, personalizaciones conservadas, aplicación, idempotencia y rollback')
