"""Instalación de los dos JS en copias privadas. Sin SQL, red ni CRM real."""
import os
from pathlib import Path
import subprocess
import tempfile
import sys
sys.dont_write_bytecode=True
ROOT=Path(__file__).resolve().parents[1]
sys.path.insert(0,str(ROOT/'tools'))
from agenda_comentarios_compat import recordatorios_hostinger_telefono
BASH=os.environ.get('IMPE_TEST_BASH','C:/Program Files/Git/bin/bash.exe' if os.name=='nt' else 'bash')
R='public/javascript/recordatorios.js';A='public/javascript/agenda_comentarios.js'
def source(ref,file):return subprocess.check_output(['git','show',ref+':'+file],cwd=ROOT).decode('utf8')
latest={f:(ROOT/f).read_text(encoding='utf8') for f in [R,A]}
for r_ref,a_ref in [('7bca643d','9256fa4a'),('9256fa4a','0037e45f'),('5116b62c','9256fa4a')]:
 for phone in [False,True]:
  for crlf in [False,True]:
   with tempfile.TemporaryDirectory(prefix='comentarios-integracion-',dir=ROOT/'tmp') as tmp:
    area=Path(tmp);live=area/'web';live.mkdir();(live/'index.php').write_text('<?php',encoding='utf8')
    before={R:source(r_ref,R),A:source(a_ref,A)};after=dict(latest)
    if phone:before[R],after[R]=recordatorios_hostinger_telefono(before[R],after[R])
    for f in [R,A]:
     before[f]+='\n// conservar personalizacion\n';after[f]+='\n// conservar personalizacion\n'
     if crlf:before[f]=before[f].replace('\n','\r\n');after[f]=after[f].replace('\n','\r\n')
     target=live/f;target.parent.mkdir(parents=True,exist_ok=True);target.write_bytes(before[f].encode())
    css=live/'public/css/agenda_comentarios.css';css.parent.mkdir(parents=True);css.write_text('gris 18px',encoding='utf8')
    env=dict(os.environ,IMPE_CORREOS_PUBLIC=str(live),IMPE_CORREOS_BACKUPS=str(area/'backups'),
             PATH=('C:/xampp/php'+os.pathsep if os.name=='nt' else '')+os.environ.get('PATH',''))
    def run(*args,ok=True):
     p=subprocess.run([BASH,str(ROOT/'tools/publicar_integracion_toasts_comentarios_hostinger.sh'),*args],env=env,capture_output=True,text=True,encoding='utf8')
     assert (p.returncode==0)==ok,(r_ref,a_ref,phone,crlf,p.stdout,p.stderr)
     return p.stdout+p.stderr
    assert '2 archivo(s) pendientes' in run('--check')
    assert all((live/f).read_bytes()==before[f].encode() for f in before) and not (area/'backups').exists()
    run('--apply');assert all((live/f).read_bytes()==after[f].encode() for f in after)
    assert css.read_text()=='gris 18px' and 'sin cambios pendientes' in run('--apply')
    backup=next((area/'backups').iterdir())
    (live/A).write_bytes((live/A).read_bytes()+b'\n// posterior\n')
    changed={f:(live/f).read_bytes() for f in before};run('--restore',str(backup),ok=False)
    assert all((live/f).read_bytes()==changed[f] for f in changed)
    (live/A).write_bytes(after[A].encode());run('--restore',str(backup))
    assert all((live/f).read_bytes()==before[f].encode() for f in before)
    incompatible=before[A].replace('const refreshBell = () =>','const refreshBellCustom = () =>')
    assert incompatible!=before[A]
    (live/A).write_bytes(incompatible.encode())
    changed={f:(live/f).read_bytes() for f in before};run('--apply',ok=False)
    assert all((live/f).read_bytes()==changed[f] for f in changed)
print('OK: 12 variantes; dos archivos, base/lectura/confirmación previa, formato telefónico, LF/CRLF, idempotencia, restore y CSS intacto. Sin SQL ni red.')
