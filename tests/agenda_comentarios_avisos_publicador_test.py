"""Publicación y restauración de un archivo sobre copias, sin red ni SQL."""
import os
from pathlib import Path
import subprocess
import tempfile
import sys
sys.dont_write_bytecode=True
ROOT=Path(__file__).resolve().parents[1]
sys.path.insert(0,str(ROOT/'tools'))
from agenda_comentarios_compat import recordatorios_hostinger, recordatorios_hostinger_telefono
BASH=os.environ.get('IMPE_TEST_BASH','C:/Program Files/Git/bin/bash.exe' if os.name=='nt' else 'bash')
CONFIRMACION='--confirmacion' in sys.argv
FILE='public/javascript/agenda_comentarios.js' if CONFIRMACION else 'public/javascript/recordatorios.js'
BASE='9256fa4a' if CONFIRMACION else '7bca643d'
SCRIPT='publicar_confirmacion_comentario_hostinger.sh' if CONFIRMACION else 'publicar_avisos_comentarios_hostinger.sh'
old=subprocess.check_output(['git','show',BASE+':'+FILE],cwd=ROOT).decode('utf8')
new=(ROOT/FILE).read_text(encoding='utf8')
variants=[(old,new)]
if CONFIRMACION:
 # El parche no cambia la preferencia de pestaña instalada en Hostinger.
 variants.append((old.replace("tab = 'reuniones'","tab = 'comentarios'").replace("tab='reuniones';openDay(day.dataset.agendaDate)","tab='comentarios';openDay(day.dataset.agendaDate)"),
                  new.replace("tab = 'reuniones'","tab = 'comentarios'").replace("tab='reuniones';openDay(day.dataset.agendaDate)","tab='comentarios';openDay(day.dataset.agendaDate)")))
else:
 host_old,host_new=recordatorios_hostinger(old,new)
 legacy=subprocess.check_output(['git','show','5116b62c:'+FILE],cwd=ROOT).decode('utf8')
 legacy_host,_=recordatorios_hostinger(legacy,new)
 phone_old,phone_new=recordatorios_hostinger_telefono(old,new)
 legacy_phone,_=recordatorios_hostinger_telefono(legacy,new)
 variants.extend([(host_old,host_new),(phone_old,phone_new),(legacy,new),(legacy_host,host_new),(legacy_phone,phone_new)])
for before,after in variants:
 for crlf in [False,True]:
  with tempfile.TemporaryDirectory(prefix='avisos-publish-',dir=ROOT/'tmp') as tmp:
   area=Path(tmp);live=area/'web';live.mkdir();(live/'index.php').write_text('<?php',encoding='utf8')
   css=live/'public/css/agenda_comentarios.css';css.parent.mkdir(parents=True);css.write_text('/* gris personalizado */',encoding='utf8')
   source=before+'\n// personalizacion ajena\n';expected=after+'\n// personalizacion ajena\n'
   if crlf:source=source.replace('\n','\r\n');expected=expected.replace('\n','\r\n')
   target=live/FILE;target.parent.mkdir(parents=True);target.write_bytes(source.encode())
   env=dict(os.environ,IMPE_CORREOS_PUBLIC=str(live),IMPE_CORREOS_BACKUPS=str(area/'backups'),
            PATH=('C:/xampp/php'+os.pathsep if os.name=='nt' else '')+os.environ.get('PATH',''))
   def run(*args,ok=True):
    p=subprocess.run([BASH,str(ROOT/'tools'/SCRIPT),*args],env=env,capture_output=True,text=True,encoding='utf8')
    assert (p.returncode==0)==ok,(p.stdout,p.stderr)
    return p.stdout+p.stderr
   assert '1 archivo(s) pendientes' in run('--check') and target.read_bytes()==source.encode()
   assert not (area/'backups').exists()
   assert '--apply terminado' in run('--apply') and target.read_bytes()==expected.encode()
   assert css.read_text()=='/* gris personalizado */'
   assert 'sin cambios pendientes' in run('--apply')
   backup=next((area/'backups').iterdir())
   target.write_bytes(target.read_bytes()+b'\n// posterior\n');changed=target.read_bytes()
   run('--restore',str(backup),ok=False);assert target.read_bytes()==changed
   target.write_bytes(expected.encode());run('--restore',str(backup));assert target.read_bytes()==source.encode()
   source_bad=source.replace("status.classList.toggle('is-error', failure)","status.classList.toggle('custom-error', failure)") if CONFIRMACION else source.replace('const actualizarColaAvisos = function','const colaDistinta = function')
   assert source_bad!=source
   target.write_bytes(source_bad.encode())
   changed=target.read_bytes();run('--apply',ok=False);assert target.read_bytes()==changed
print('OK: un archivo, base/Hostinger y LF/CRLF, check, idempotencia, respaldo/restore, rechazo de cambios, CSS intacto. Sin SQL ni red.')
