"""Publicador de vistos sobre copias de versiones publicadas, sin red ni SQL."""
import os
from pathlib import Path
import subprocess
import tempfile
import sys
sys.dont_write_bytecode=True
ROOT=Path(__file__).resolve().parents[1];sys.path.insert(0,str(ROOT/'tools'))
from agenda_comentarios_compat import recordatorios_hostinger_actual
BASH='C:/Program Files/Git/bin/bash.exe' if os.name=='nt' else 'bash'
R='public/javascript/recordatorios.js';A='public/javascript/agenda_comentarios.js'
BACKEND=['app/services/AgendaComentarioRepository.php','app/services/AgendaComentarioService.php','app/controllers/AgendaComentarioController.php']
ADDED=['database/migrations/2026_10_28_agenda_comentarios_vistos.sql','tools/configurar_avisos_vistos_agenda.php']
def git(ref,p):return subprocess.check_output(['git','show',ref+':'+p],cwd=ROOT).decode('utf8')
for ref_r,ref_a in [('9256fa4a','0037e45f'),('98e13a75','98e13a75')]:
 for crlf in [False,True]:
  with tempfile.TemporaryDirectory(prefix='vistos-publish-',dir=ROOT/'tmp') as tmp:
   area=Path(tmp);live=area/'web';live.mkdir();(live/'index.php').write_text('<?php',encoding='utf8')
   old={p:git('98e13a75',p) for p in BACKEND};old[R]=git(ref_r,R);old[A]=git(ref_a,A)
   new={p:(ROOT/p).read_text(encoding='utf8') for p in old}
   old[R],new[R]=recordatorios_hostinger_actual(old[R],new[R])
   for p in old:
    old[p]+='\n// conservar\n';new[p]+='\n// conservar\n'
    if crlf:old[p]=old[p].replace('\n','\r\n');new[p]=new[p].replace('\n','\r\n')
    f=live/p;f.parent.mkdir(parents=True,exist_ok=True);f.write_bytes(old[p].encode())
   css=live/'public/css/agenda_comentarios.css';css.parent.mkdir(parents=True);css.write_text('gris claro 16px',encoding='utf8')
   env=dict(os.environ,IMPE_CORREOS_PUBLIC=str(live),IMPE_CORREOS_BACKUPS=str(area/'backups'),PATH=('C:/xampp/php'+os.pathsep if os.name=='nt' else '')+os.environ.get('PATH',''))
   def run(*args,ok=True):
    r=subprocess.run([BASH,str(ROOT/'tools/publicar_avisos_vistos_agenda_hostinger.sh'),*args],env=env,capture_output=True,text=True,encoding='utf8')
    assert (r.returncode==0)==ok,(r.stdout,r.stderr);return r.stdout+r.stderr
   assert '7 archivo(s) pendientes' in run('--check') and all((live/p).read_bytes()==old[p].encode() for p in old)
   assert all(not (live/p).exists() for p in ADDED)
   run('--apply');assert all((live/p).read_bytes()==new[p].encode() for p in new)
   assert all((live/p).read_bytes()==(ROOT/p).read_bytes().replace(b'\r\n',b'\n') for p in ADDED)
   assert css.read_text()=='gris claro 16px' and 'sin cambios pendientes' in run('--apply')
   backup=next((area/'backups').iterdir());run('--restore',str(backup))
   assert all((live/p).read_bytes()==old[p].encode() for p in old) and all(not (live/p).exists() for p in ADDED)
print('OK: versiones publicadas y LF/CRLF; 7 archivos, check intacto, idempotencia, respaldo/restauración, CSS y permisos intactos. Sin SQL ni red.')
