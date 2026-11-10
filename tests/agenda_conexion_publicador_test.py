"""Parches exactos de dos JS, incluidos los publicados, sin SQL ni red."""
import os
from pathlib import Path
import subprocess
import tempfile

ROOT=Path(__file__).resolve().parents[1];BASH='C:/Program Files/Git/bin/bash.exe'
FILES=['public/javascript/agenda_comentarios.js','public/javascript/agenda_reunion.js']
for ref in ['8a5b2f23','fd787fb7']:
 for crlf in [False,True]:
  with tempfile.TemporaryDirectory(prefix='agenda-conexion-pub-',dir=ROOT/'tmp') as tmp:
    area=Path(tmp);live=area/'web';live.mkdir();(live/'index.php').write_text('<?php',encoding='utf8')
    old={};new={}
    for path in FILES:
        before=subprocess.check_output(['git','show',ref+':'+path],cwd=ROOT).decode('utf8')+'\n// personalización externa\n'
        after=(ROOT/path).read_text(encoding='utf8')+'\n// personalización externa\n'
        if crlf:before=before.replace('\n','\r\n');after=after.replace('\n','\r\n')
        p=live/path;p.parent.mkdir(parents=True,exist_ok=True);p.write_bytes(before.encode());old[path]=before.encode();new[path]=after.encode()
    protected=['public/javascript/recordatorios.js','public/javascript/agenda_fechas_referencia.js','app/services/AgendaComentarioService.php','app/services/AgendaReunionRepository.php']
    for path in protected:
        p=live/path;p.parent.mkdir(parents=True,exist_ok=True);p.write_bytes((ROOT/path).read_bytes())
    env=dict(os.environ,IMPE_CORREOS_PUBLIC=str(live),IMPE_CORREOS_BACKUPS=str(area/'backups'),PATH='C:/xampp/php'+os.pathsep+os.environ['PATH'])
    def run(*args,ok=True):
        r=subprocess.run([BASH,str(ROOT/'tools/publicar_agenda_conexion_hostinger.sh'),*args],env=env,capture_output=True,text=True,encoding='utf8')
        assert (r.returncode==0)==ok,(r.stdout,r.stderr);return r.stdout+r.stderr
    assert '2 archivo(s) pendientes' in run('--check') and all((live/p).read_bytes()==old[p] for p in FILES)
    run('--apply');assert all((live/p).read_bytes()==new[p] for p in FILES)
    assert 'sin cambios pendientes' in run('--apply')
    backup=next((area/'backups').iterdir());run('--restore',str(backup));assert all((live/p).read_bytes()==old[p] for p in FILES)
    assert all((live/p).read_bytes()==(ROOT/p).read_bytes() for p in protected)
    (live/FILES[0]).write_text('incompatible',encoding='utf8')
    assert 'SITIO INTACTO' in run('--apply',ok=False) and (live/FILES[1]).read_bytes()==old[FILES[1]]
print('OK: dos variantes publicadas, LF/CRLF, idempotencia, respaldo/restore, rechazo previo y módulos/SQL/avisos intactos.')
