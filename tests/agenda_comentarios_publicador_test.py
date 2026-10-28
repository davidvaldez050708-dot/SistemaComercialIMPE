"""Publicador real sobre copias aisladas. No red, SQL ni sitio publicado."""
import hashlib
import os
from pathlib import Path
import shutil
import subprocess
import tempfile

ROOT=Path(__file__).resolve().parents[1]
BASE='5116b62c284de2f9e27440f77982964e876c7402'
BASH=os.environ.get('IMPE_TEST_BASH','C:/Program Files/Git/bin/bash.exe' if os.name=='nt' else 'bash')
PHP_DIR=os.environ.get('IMPE_TEST_PHP_DIR','C:/xampp/php' if os.name=='nt' else '')
SCRIPT=ROOT/'tools/publicar_agenda_comentarios_hostinger.sh'
FILES=['index.php','app/views/seguimiento_vinculacion/agenda.php','app/views/layout/topbar.php',
       'app/controllers/ReminderController.php','public/javascript/recordatorios.js']
NEW=['app/controllers/AgendaComentarioController.php','app/services/AgendaComentarioRepository.php',
     'app/services/AgendaComentarioService.php','app/views/seguimiento_vinculacion/partials/agenda_comentarios.php',
     'public/css/agenda_comentarios.css','public/javascript/agenda_comentarios.js',
     'database/migrations/2026_10_28_agenda_comentarios.sql','tools/configurar_agenda_comentarios.php']
def snapshot(live):
    return {name:hashlib.sha256((live/name).read_bytes()).hexdigest() if (live/name).is_file() else None for name in FILES+NEW}
def run(env,*args,ok=True):
    p=subprocess.run([BASH,str(SCRIPT),*args],env=env,capture_output=True,text=True,encoding='utf8')
    assert (p.returncode==0)==ok,(p.returncode,p.stdout,p.stderr)
    return p.stdout+p.stderr

(ROOT/'tmp').mkdir(exist_ok=True)
for crlf in [False,True]:
    with tempfile.TemporaryDirectory(prefix='agenda-publish-',dir=ROOT/'tmp') as tmp:
        area=Path(tmp);live=area/'web';live.mkdir()
        env=dict(os.environ,IMPE_CORREOS_PUBLIC=str(live),IMPE_CORREOS_BACKUPS=str(area/'backups'),
                 PATH=PHP_DIR+os.pathsep+os.environ.get('PATH',''))
        for name in FILES:
            text=subprocess.check_output(['git','show',BASE+':'+name],cwd=ROOT).decode('utf8')
            # Conservar personalizaciones ajenas a las cinco integraciones.
            text='<!-- personalizacion conservada -->\n'+text if '/views/' in name else text+'\n// personalizacion conservada\n'
            if crlf:text=text.replace('\n','\r\n')
            p=live/name;p.parent.mkdir(parents=True,exist_ok=True);p.write_bytes(text.encode())
        original=snapshot(live)
        assert '13 archivo(s) pendientes' in run(env,'--check')
        assert snapshot(live)==original and not (area/'backups').exists()
        assert '13 archivo(s) pendientes' in run(env)
        applied=run(env,'--apply');assert '13 archivo(s)' in applied
        backup=next((area/'backups').iterdir());assert (backup/'manifest.json').is_file()
        for name in FILES:
            content=(live/name).read_bytes();assert b'personalizacion conservada' in content
            assert (b'\r\n' in content)==crlf
        for name in NEW:assert (live/name).read_bytes().replace(b'\r\n',b'\n')==(ROOT/name).read_bytes().replace(b'\r\n',b'\n')
        installed=snapshot(live)
        assert '0 archivo(s) pendientes' in run(env,'--check')
        assert 'sin cambios pendientes' in run(env,'--apply') and snapshot(live)==installed
        # Un archivo personalizado posterior impide cualquier restauración parcial.
        js=live/'public/javascript/agenda_comentarios.js';saved=js.read_bytes();js.write_bytes(saved+b'\n// posterior\n')
        edited=snapshot(live);assert 'editado después' in run(env,'--restore',str(backup),ok=False)
        assert snapshot(live)==edited
        js.write_bytes(saved)
        assert 'Los comentarios y sus lecturas se conservan' in run(env,'--restore',str(backup))
        assert snapshot(live)==original
        assert 'sin cambios pendientes' in run(env,'--restore',str(backup))
        index=live/'index.php';index.write_bytes(index.read_bytes().replace(b"case 'agendaReunion':",b"case 'agendaDistinta':"))
        incompatible=snapshot(live);assert 'incompatible' in run(env,'--check',ok=False)
        assert snapshot(live)==incompatible
        assert 'incompatible' in run(env,'--apply',ok=False) and snapshot(live)==incompatible
        assert not (area/'.impe-correos-deploy.lock').exists()
print('OK: publicador LF/CRLF, 13 archivos, cambios locales conservados, check inmutable, aplicación idempotente, respaldo/restauración y rechazo de incompatibles. Sin SQL ni red.')
