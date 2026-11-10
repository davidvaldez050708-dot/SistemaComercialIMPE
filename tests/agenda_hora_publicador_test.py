"""Instalación del selector nativo en copias aisladas LF/CRLF. Sin red ni BD."""
import os
from pathlib import Path
import subprocess
import tempfile

ROOT=Path(__file__).resolve().parents[1]
BASH='C:/Program Files/Git/bin/bash.exe' if os.name=='nt' else 'bash'
FILES=['app/views/seguimiento_vinculacion/partials/agenda_comentarios.php','public/css/agenda_comentarios.css','public/javascript/agenda_comentarios.js']
for crlf in [False,True]:
 with tempfile.TemporaryDirectory(prefix='hora-native-',dir=ROOT/'tmp') as tmp:
    area=Path(tmp);live=area/'web';live.mkdir();(live/'index.php').write_text('<?php',encoding='utf8')
    original={};expected={}
    for path in FILES:
        before=subprocess.check_output(['git','show','a119dfe5:'+path],cwd=ROOT).decode('utf8')
        after=(ROOT/path).read_text(encoding='utf8')
        suffix='\n/* conservar iconos grises */\n' if path.endswith('.css') else '\n// conservar\n'
        before+=suffix;after+=suffix
        if crlf:before=before.replace('\n','\r\n');after=after.replace('\n','\r\n')
        f=live/path;f.parent.mkdir(parents=True,exist_ok=True);f.write_bytes(before.encode())
        original[path]=before.encode();expected[path]=after.encode()
    untouched=['app/services/AgendaComentarioService.php','app/services/AgendaComentarioRepository.php','public/javascript/recordatorios.js']
    for path in untouched:
        f=live/path;f.parent.mkdir(parents=True,exist_ok=True);f.write_bytes((ROOT/path).read_bytes())
    env=dict(os.environ,IMPE_CORREOS_PUBLIC=str(live),IMPE_CORREOS_BACKUPS=str(area/'backups'),
             PATH=('C:/xampp/php'+os.pathsep if os.name=='nt' else '')+os.environ.get('PATH',''))
    def run(*args,ok=True):
        r=subprocess.run([BASH,str(ROOT/'tools/publicar_hora_agenda_hostinger.sh'),*args],env=env,capture_output=True,text=True,encoding='utf8')
        assert (r.returncode==0)==ok,(r.stdout,r.stderr);return r.stdout+r.stderr
    assert '3 archivo(s) pendientes' in run('--check')
    assert all((live/p).read_bytes()==original[p] for p in FILES)
    run('--apply');assert all((live/p).read_bytes()==expected[p] for p in FILES)
    assert 'sin cambios pendientes' in run('--apply')
    backup=next((area/'backups').iterdir());run('--restore',str(backup))
    assert all((live/p).read_bytes()==original[p] for p in FILES)
    assert all((live/p).read_bytes()==(ROOT/p).read_bytes() for p in untouched)
    (live/FILES[-1]).write_text('incompatible',encoding='utf8')
    assert 'SITIO INTACTO' in run('--check',ok=False)
    assert all((live/p).read_bytes()==original[p] for p in FILES[:-1])
print('OK: tres archivos, LF/CRLF, check inmutable, idempotencia, respaldo/restore y privacidad/avisos intactos. Sin SQL.')
