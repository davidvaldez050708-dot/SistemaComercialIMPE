"""Publicador JSON en copias aisladas, sin SQL ni red."""
import os
from pathlib import Path
import subprocess
import tempfile

ROOT=Path(__file__).resolve().parents[1]
BASH='C:/Program Files/Git/bin/bash.exe' if os.name=='nt' else 'bash'
CHANGED=['app/controllers/AgendaReunionController.php','public/javascript/agenda_reunion.js']
ADDED='tools/diagnosticar_confirmacion_reunion.php'
for crlf in [False,True]:
 with tempfile.TemporaryDirectory(prefix='confirm-json-',dir=ROOT/'tmp') as tmp:
    area=Path(tmp);live=area/'web';live.mkdir();(live/'index.php').write_text('<?php',encoding='utf8')
    before={};after={}
    for path in CHANGED:
        old=subprocess.check_output(['git','show','8a5b2f23:'+path],cwd=ROOT).decode('utf8')+'\n// conservar\n'
        new=(ROOT/path).read_text(encoding='utf8')+'\n// conservar\n'
        if crlf:old=old.replace('\n','\r\n');new=new.replace('\n','\r\n')
        f=live/path;f.parent.mkdir(parents=True,exist_ok=True);f.write_bytes(old.encode());before[path]=old.encode();after[path]=new.encode()
    protected=['app/services/AgendaReunionRepository.php','app/services/ReprogramacionReunionService.php',
               'app/services/AgendaReunionService.php','public/javascript/recordatorios.js','public/css/agenda_comentarios.css']
    for path in protected:
        f=live/path;f.parent.mkdir(parents=True,exist_ok=True);f.write_bytes((ROOT/path).read_bytes())
    env=dict(os.environ,IMPE_CORREOS_PUBLIC=str(live),IMPE_CORREOS_BACKUPS=str(area/'backups'),PATH=('C:/xampp/php'+os.pathsep if os.name=='nt' else '')+os.environ.get('PATH',''))
    def run(*args,ok=True):
        r=subprocess.run([BASH,str(ROOT/'tools/publicar_confirmacion_json_hostinger.sh'),*args],env=env,capture_output=True,text=True,encoding='utf8')
        assert (r.returncode==0)==ok,(r.stdout,r.stderr);return r.stdout+r.stderr
    assert '3 archivo(s) pendientes' in run('--check') and not(live/ADDED).exists()
    assert all((live/p).read_bytes()==before[p] for p in CHANGED)
    run('--apply');assert all((live/p).read_bytes()==after[p] for p in CHANGED)
    assert (live/ADDED).read_bytes()==(ROOT/ADDED).read_bytes().replace(b'\r\n',b'\n')
    assert 'sin cambios pendientes' in run('--apply')
    backup=next((area/'backups').iterdir());run('--restore',str(backup))
    assert all((live/p).read_bytes()==before[p] for p in CHANGED) and not(live/ADDED).exists()
    assert all((live/p).read_bytes()==(ROOT/p).read_bytes() for p in protected)
    (live/CHANGED[-1]).write_text('incompatible',encoding='utf8')
    assert 'SITIO INTACTO' in run('--apply',ok=False) and not(live/ADDED).exists()
    assert (live/CHANGED[0]).read_bytes()==before[CHANGED[0]]
print('OK: publicador JSON LF/CRLF, check inmutable, idempotencia, rechazo previo, respaldo/restore y servicios/reglas/avisos intactos.')
