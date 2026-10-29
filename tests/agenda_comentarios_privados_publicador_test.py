"""Publicador real en copias aisladas LF/CRLF y CSS publicado. Sin red ni BD real."""
import os
from pathlib import Path
import subprocess
import tempfile

ROOT=Path(__file__).resolve().parents[1]
BASH='C:/Program Files/Git/bin/bash.exe' if os.name=='nt' else 'bash'
BASE='192ebe0d'
CHANGED=['app/services/AgendaComentarioRepository.php','app/services/AgendaComentarioService.php',
         'app/views/seguimiento_vinculacion/partials/agenda_comentarios.php','public/css/agenda_comentarios.css','public/javascript/agenda_comentarios.js']
ADDED=['database/migrations/2026_10_29_agenda_comentarios_privados.sql','tools/configurar_agenda_comentarios_privados.php']
for crlf in [False,True]:
 with tempfile.TemporaryDirectory(prefix='privados-publish-',dir=ROOT/'tmp') as tmp:
    area=Path(tmp);live=area/'web';live.mkdir();(live/'index.php').write_text('<?php',encoding='utf8')
    (live/'config').mkdir()
    (live/'config/db_connection.php').write_text('''<?php
class ResultadoGuard {public function __construct(private $row){} public function fetch_assoc(){return $this->row;}}
class ConexionGuard {public function query($sql){return new ResultadoGuard(str_starts_with($sql,'SHOW')?['Field'=>'privado']:['total'=>file_get_contents(__DIR__.'/../private_count')]);}}
class Database {public function connect($persistent=false){return new ConexionGuard();}}
''',encoding='utf8')
    (live/'private_count').write_text('0',encoding='utf8')
    untouched=live/'public/javascript/recordatorios.js';untouched.parent.mkdir(parents=True)
    untouched.write_text('duración 8000 ms, avisos y telefonía intactos',encoding='utf8')
    originals={};expected={}
    for path in CHANGED:
        old=subprocess.check_output(['git','show',BASE+':'+path],cwd=ROOT).decode('utf8')
        new=(ROOT/path).read_text(encoding='utf8')
        suffix='\n/* conservar personalización externa */\n' if path.endswith('.css') else '\n// conservar\n'
        old+=suffix;new+=suffix
        if crlf:old=old.replace('\n','\r\n');new=new.replace('\n','\r\n')
        f=live/path;f.parent.mkdir(parents=True,exist_ok=True);f.write_bytes(old.encode())
        originals[path]=old.encode();expected[path]=new.encode()
    env=dict(os.environ,IMPE_CORREOS_PUBLIC=str(live),IMPE_CORREOS_BACKUPS=str(area/'backups'),
             PATH=('C:/xampp/php'+os.pathsep if os.name=='nt' else '')+os.environ.get('PATH',''))
    def run(*args,ok=True):
        r=subprocess.run([BASH,str(ROOT/'tools/publicar_agenda_privados_hostinger.sh'),*args],env=env,capture_output=True,text=True,encoding='utf8')
        assert (r.returncode==0)==ok,(r.stdout,r.stderr);return r.stdout+r.stderr
    assert '7 archivo(s) pendientes' in run('--check')
    assert all((live/p).read_bytes()==originals[p] for p in CHANGED) and all(not(live/p).exists() for p in ADDED)
    run('--apply');assert all((live/p).read_bytes()==expected[p] for p in CHANGED)
    assert 'sin cambios pendientes' in run('--apply')
    backup=next((area/'backups').iterdir())
    (live/'private_count').write_text('1',encoding='utf8')
    assert 'SITIO INTACTO' in run('--restore',str(backup),ok=False)
    assert all((live/p).read_bytes()==expected[p] for p in CHANGED)
    (live/'private_count').write_text('0',encoding='utf8')
    run('--restore',str(backup))
    assert all((live/p).read_bytes()==originals[p] for p in CHANGED) and all(not(live/p).exists() for p in ADDED)
    assert untouched.read_text(encoding='utf8')=='duración 8000 ms, avisos y telefonía intactos'
    # Una incompatibilidad no produce instalación parcial ni archivos nuevos.
    target=live/'public/javascript/agenda_comentarios.js';target.write_text('personalizado',encoding='utf8')
    assert 'SITIO INTACTO' in run('--check',ok=False)
    assert all(not(live/p).exists() for p in ADDED)
print('OK: publicador 7 archivos, LF/CRLF, CSS externo conservado, sin SQL en check/apply, idempotencia, respaldo y rollback sin exponer privados.')
