"""Integración visual, respaldo/restore y preservación de módulos. Sin BD o red."""
import os
from pathlib import Path
import subprocess
import tempfile

ROOT=Path(__file__).resolve().parents[1];BASH='C:/Program Files/Git/bin/bash.exe'
VIEW='app/views/seguimiento_vinculacion/agenda.php'
ADDED=['app/data/agenda_fechas_2026_2027.json','public/css/agenda_fechas_referencia.css',
       'public/javascript/agenda_fechas_referencia.js','app/views/seguimiento_vinculacion/partials/agenda_fechas_referencia.php']
for crlf in [False,True]:
 with tempfile.TemporaryDirectory(prefix='agenda-fechas-pub-',dir=ROOT/'tmp') as tmp:
    area=Path(tmp);live=area/'web';live.mkdir();(live/'index.php').write_text('<?php',encoding='utf8')
    original=subprocess.check_output(['git','show','cce2534a:'+VIEW],cwd=ROOT).decode('utf8')+'\n<!-- contenido propio conservado -->\n'
    expected=(ROOT/VIEW).read_text(encoding='utf8')+'\n<!-- contenido propio conservado -->\n'
    if crlf:original=original.replace('\n','\r\n');expected=expected.replace('\n','\r\n')
    path=live/VIEW;path.parent.mkdir(parents=True);path.write_bytes(original.encode())
    protected=['app/services/AgendaReunionRepository.php','app/services/AgendaComentarioService.php',
               'public/javascript/agenda_reunion.js','public/javascript/agenda_comentarios.js','public/javascript/recordatorios.js']
    for file in protected:
        p=live/file;p.parent.mkdir(parents=True,exist_ok=True);p.write_bytes((ROOT/file).read_bytes())
    env=dict(os.environ,IMPE_CORREOS_PUBLIC=str(live),IMPE_CORREOS_BACKUPS=str(area/'backups'),PATH='C:/xampp/php'+os.pathsep+os.environ['PATH'])
    def run(*args,ok=True):
        r=subprocess.run([BASH,str(ROOT/'tools/publicar_agenda_fechas_hostinger.sh'),*args],env=env,capture_output=True,text=True,encoding='utf8')
        assert (r.returncode==0)==ok,(r.stdout,r.stderr);return r.stdout+r.stderr
    assert '5 archivo(s) pendientes' in run('--check')
    assert path.read_bytes()==original.encode() and all(not(live/p).exists() for p in ADDED)
    run('--apply');assert path.read_bytes()==expected.encode()
    assert all((live/p).read_bytes()==(ROOT/p).read_bytes().replace(b'\r\n',b'\n') for p in ADDED)
    assert 'sin cambios pendientes' in run('--apply')
    backup=next((area/'backups').iterdir());run('--restore',str(backup))
    assert path.read_bytes()==original.encode() and all(not(live/p).exists() for p in ADDED)
    assert all((live/p).read_bytes()==(ROOT/p).read_bytes() for p in protected)
    path.write_bytes(original.replace('agenda-mobile-scroll-hint','bloque-distinto').encode())
    assert 'SITIO INTACTO' in run('--apply',ok=False) and all(not(live/p).exists() for p in ADDED)
print('OK: cinco archivos, LF/CRLF, check intacto, idempotencia, respaldo/restore y rechazo seguro; servicios, comentarios y avisos intactos.')
