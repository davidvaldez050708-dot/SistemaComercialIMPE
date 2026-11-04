"""Prueba de publicación selectiva del aviso, sin conexión a producción."""
import hashlib
import os
from pathlib import Path
import subprocess
import sys
import tempfile

R=Path(__file__).resolve().parents[1]
SCRIPT=R/'tools/publicar_analista_aviso_estable.py'
PREVIOUS='1faca902a2b4ea8b4ea1665b2e798fa5a2913a79'
FILES=['public/javascript/seguimiento_llamada_registro_obligatorio.js',
       'public/javascript/seguimiento_llamada_registro_compacto.js']
def previous(name):
    return subprocess.check_output(['git','show',PREVIOUS+':'+name],cwd=str(R))
def sha(contents):
    return hashlib.sha256(contents).hexdigest()

with tempfile.TemporaryDirectory(prefix='impe-aviso-estable-test-') as tmp:
    root=Path(tmp);site=root/'site';site.mkdir()
    (site/'index.php').write_text('<?php // fixture',encoding='utf-8')
    backup=root/'private';payload=root/'payload';payload.mkdir()
    old={}
    for name in FILES:
        old[name]=previous(name)
        path=site/name;path.parent.mkdir(parents=True,exist_ok=True);path.write_bytes(old[name])
        (payload/Path(name).name).write_bytes((R/name).read_bytes())
    def snapshot():
        return {p.relative_to(site).as_posix():p.read_bytes()
                for p in site.rglob('*') if p.is_file()}
    def run(*args,**opts):
        out=subprocess.run([sys.executable,str(SCRIPT),'--site',str(site),
                            '--backup-root',str(backup),'--payload',str(payload)]+list(args),
                           stdout=subprocess.PIPE,stderr=subprocess.PIPE)
        output=(out.stdout+out.stderr).decode('utf-8',errors='replace')
        assert (out.returncode==0)==opts.get('ok',True),output
        return output
    before=snapshot()
    assert 'CHECK OK' in run('--check')
    assert snapshot()==before and not backup.exists(),'--check modificó algo'
    changed=site/FILES[1]
    changed.write_bytes(changed.read_bytes()+b'\n// personalizado')
    expected=snapshot()
    assert 'VERSIÓN DIFERENTE' in run('--apply',ok=False)
    assert snapshot()==expected and not backup.exists(),'Conflicto alteró el sitio'
    changed.write_bytes(old[FILES[1]])
    payload_js=payload/Path(FILES[0]).name
    payload_js.write_bytes(payload_js.read_bytes()+b'\n// corrupto')
    assert 'PAQUETE ALTERADO' in run('--check',ok=False)
    payload_js.write_bytes((R/FILES[0]).read_bytes())
    assert 'APLICADO' in run('--apply')
    for name in FILES:
        assert (site/name).read_bytes()==(R/name).read_bytes()
    now=snapshot();folder=next(f for f in backup.iterdir() if f.is_dir())
    assert 'YA INSTALADO' in run('--apply')
    assert len([f for f in backup.iterdir() if f.is_dir()])==1,'Segundo respaldo innecesario'
    changed.write_bytes(changed.read_bytes()+b'\n// edicion posterior')
    assert 'modificada' in run('--restore',str(folder),ok=False)
    changed.write_bytes(now[FILES[1]])
    assert 'RESTAURADO' in run('--restore',str(folder))
    assert snapshot()==before,'Reversión no recuperó originales exactos'
print('PASS aviso estable: check, hash, conflictos, respaldo, idempotencia, rollback y dos archivos')
