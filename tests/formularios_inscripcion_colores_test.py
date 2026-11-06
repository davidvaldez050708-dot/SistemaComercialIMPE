"""Regresión de estilos de Inscripción y publicador CSS de un solo archivo."""
from pathlib import Path
import subprocess
import sys
import tempfile

ROOT = Path(__file__).resolve().parents[1]
PUBLISHER = ROOT / 'tools/publicar_inscripcion_acciones_azules_hostinger.py'
TARGET = ROOT / 'public/css/formularios.css'
BASE_SHA = 'c2375488f333fc82af669e09839bd3f8c92376ee'

old = subprocess.run(['git','show', BASE_SHA+':public/css/formularios.css'],
    cwd=str(ROOT), stdout=subprocess.PIPE, stderr=subprocess.PIPE)
assert old.returncode == 0, old.stderr.decode('utf-8','replace')
base_css = old.stdout
new_css = TARGET.read_bytes().replace(b'\r\n', b'\n')
assert new_css.startswith(base_css.rstrip(b'\n'))
assert b'ACCIONES AZULES Y ACENTOS VERDES' in new_css
assert b'.formularios-inscripcion-theme .btn-system-save' in new_css
assert b'background: #223a84;' in new_css
assert b'background: #1a2e70;' in new_css
assert b'.formularios-inscripcion-programa-icon' in new_css
assert b'color: #09836b;' in new_css
assert b'.formularios-registro-theme .btn-system-save' not in new_css[
    len(base_css):], 'no alterar botones de Registro'
assert new_css.count(b'ACCIONES AZULES Y ACENTOS VERDES') == 1

with tempfile.TemporaryDirectory(prefix='inscripcion-colores-') as tmp:
    root=Path(tmp)
    site=root/'public_html'
    real=site/'public/css/formularios.css'
    base=root/'base.css'
    target=root/'target.css'
    backup=root/'respaldo-privado'
    real.parent.mkdir(parents=True)
    (site/'index.php').write_text('<?php // fixture',encoding='utf-8')
    base.write_bytes(base_css)
    target.write_bytes(new_css)
    real.write_bytes(base_css)

    def run(*args, success=True):
        proc=subprocess.run([sys.executable,str(PUBLISHER),'--site',str(site),
            '--base',str(base),'--target',str(target),
            '--backup-root',str(backup)]+list(args),
            stdout=subprocess.PIPE,stderr=subprocess.PIPE)
        output=(proc.stdout+proc.stderr).decode('utf-8','replace')
        assert (proc.returncode==0)==success,output
        return output

    assert 'CHECK OK' in run('--check')
    assert real.read_bytes()==base_css and not backup.exists(), 'check no escribe'
    assert 'APLICADO' in run('--apply')
    assert new_css.count(b'ACCIONES AZULES') == 1
    assert b'background: #223a84;' in real.read_bytes()
    assert 'YA INSTALADO' in run('--apply')
    folders=list(backup.iterdir())
    assert len(folders)==1
    assert 'RESTAURADO' in run('--restore',str(folders[0]))
    assert real.read_bytes()==base_css

    # Respetar personalizaciones anteriores que no alteran el panel.
    customized=base_css+b'\n/* estilo local de otra pantalla */\n'
    real.write_bytes(customized)
    assert 'CHECK OK' in run('--check')
    assert 'APLICADO' in run('--apply')
    patched=real.read_bytes()
    assert b'estilo local de otra pantalla' in patched
    assert b'ACCIONES AZULES Y ACENTOS VERDES' in patched
    assert 'YA INSTALADO' in run('--check')
    folders=[p for p in backup.iterdir() if p != folders[0]]
    assert len(folders)==1
    assert 'RESTAURADO' in run('--restore',str(folders[0]))
    assert real.read_bytes()==customized

    # Cambios locales en iconos o color previo bloquean publicación.
    altered=base_css.replace(b'color: #09836b;',b'color: #ff0000;',1)
    assert altered!=base_css
    real.write_bytes(altered)
    assert 'CONFLICTO' in run('--check',success=False)
    assert real.read_bytes()==altered
print('PASS Inscripción: acciones azules, iconos verdes, check/apply/restore')
