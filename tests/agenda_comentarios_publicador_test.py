"""Publicador real sobre copias aisladas. No red, SQL ni sitio publicado."""
import hashlib
import os
from pathlib import Path
import shutil
import subprocess
import tempfile
import sys
sys.dont_write_bytecode = True

ROOT=Path(__file__).resolve().parents[1]
sys.path.insert(0,str(ROOT/'tools'))
from agenda_comentarios_compat import reminder_hostinger, recordatorios_hostinger
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
for crlf,hostinger in [(False,False),(True,False),(False,True),(True,True)]:
    with tempfile.TemporaryDirectory(prefix='agenda-publish-',dir=ROOT/'tmp') as tmp:
        area=Path(tmp);live=area/'web';live.mkdir()
        expected_controller=None;expected_notices=None
        env=dict(os.environ,IMPE_CORREOS_PUBLIC=str(live),IMPE_CORREOS_BACKUPS=str(area/'backups'),
                 PATH=PHP_DIR+os.pathsep+os.environ.get('PATH',''))
        for name in FILES:
            text=subprocess.check_output(['git','show',BASE+':'+name],cwd=ROOT).decode('utf8')
            if hostinger and name=='app/views/layout/topbar.php':
                # Encabezado exacto entregado por el usuario desde Hostinger.
                text=(ROOT/'tests/fixtures/agenda_comentarios_topbar_hostinger.php').read_text(encoding='utf8')+text.split('?>',1)[1].lstrip('\n')
            if hostinger and name=='app/controllers/ReminderController.php':
                text,expected_controller=reminder_hostinger(text,(ROOT/name).read_text(encoding='utf8'))
            if hostinger and name=='public/javascript/recordatorios.js':
                text,expected_notices=recordatorios_hostinger(text,(ROOT/name).read_text(encoding='utf8'))
            # Conservar personalizaciones ajenas a las cinco integraciones.
            text='<!-- personalizacion conservada -->\n'+text if '/views/' in name else text+'\n// personalizacion conservada\n'
            if crlf:text=text.replace('\n','\r\n')
            p=live/name;p.parent.mkdir(parents=True,exist_ok=True);p.write_bytes(text.encode())
        original=snapshot(live)
        header_before=(live/'app/views/layout/topbar.php').read_bytes()
        assert '13 archivo(s) pendientes' in run(env,'--check')
        assert snapshot(live)==original and not (area/'backups').exists()
        assert '13 archivo(s) pendientes' in run(env)
        applied=run(env,'--apply');assert '13 archivo(s)' in applied
        backup=next((area/'backups').iterdir());assert (backup/'manifest.json').is_file()
        for name in FILES:
            content=(live/name).read_bytes();assert b'personalizacion conservada' in content
            assert (b'\r\n' in content)==crlf
        for name in NEW:assert (live/name).read_bytes().replace(b'\r\n',b'\n')==(ROOT/name).read_bytes().replace(b'\r\n',b'\n')
        nl=b'\r\n' if crlf else b'\n'
        anchor=b'$mostrarCentroAvisos ='+nl+b'    $esAdministradorTopbar ||'+nl
        added=b'$mostrarCentroAvisos ='+nl+b"    tienePermiso('reuniones.ver') ||"+nl+b'    $esAdministradorTopbar ||'+nl
        assert (live/'app/views/layout/topbar.php').read_bytes()==header_before.replace(anchor,added), 'Sólo cambia la línea del permiso de Agenda; reglas de Marketing intactas'
        if expected_controller:
            expected_controller+='\n// personalizacion conservada\n'
            if crlf:expected_controller=expected_controller.replace('\n','\r\n')
            assert (live/'app/controllers/ReminderController.php').read_bytes()==expected_controller.encode(), 'Tareas, permisos y orden de Hostinger intactos'
        if expected_notices:
            expected_notices+='\n// personalizacion conservada\n'
            if crlf:expected_notices=expected_notices.replace('\n','\r\n')
            assert (live/'public/javascript/recordatorios.js').read_bytes()==expected_notices.encode(), 'Clasificación de vencimientos de Hostinger intacta'
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
print('OK: publicador LF/CRLF en versión base y encabezado real de Hostinger; reglas de Marketing intactas, 13 archivos, check inmutable, idempotencia y restauración exacta. Sin SQL ni red.')
