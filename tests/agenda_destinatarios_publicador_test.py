"""Publicación en copias temporales; no accede a producción ni a la BD real."""
import os
from pathlib import Path
import subprocess
import tempfile

ROOT=Path(__file__).resolve().parents[1];BASH='C:/Program Files/Git/bin/bash.exe';BASE='5d5eb913'
CHANGED=['app/services/AgendaComentarioRepository.php','app/services/AgendaComentarioService.php',
 'app/views/seguimiento_vinculacion/partials/agenda_comentarios.php','public/css/agenda_comentarios.css','public/javascript/agenda_comentarios.js']
NEW=['database/migrations/2026_10_29_agenda_comentarios_destinatario.sql','tools/configurar_destinatarios_agenda.php']
for ref,crlf in [(BASE,False),(BASE,True),('fd787fb7',False)]:
 with tempfile.TemporaryDirectory(prefix='agenda-destinatarios-pub-',dir=ROOT/'tmp') as tmp:
    area=Path(tmp);live=area/'web';live.mkdir();(live/'index.php').write_text('<?php',encoding='utf8')
    old={};new={}
    for path in CHANGED:
        before=subprocess.check_output(['git','show',ref+':'+path],cwd=ROOT).decode('utf8')+'\n// personalización externa\n'
        after=(ROOT/path).read_text(encoding='utf8')+'\n// personalización externa\n'
        if crlf:before=before.replace('\n','\r\n');after=after.replace('\n','\r\n')
        p=live/path;p.parent.mkdir(parents=True,exist_ok=True);p.write_bytes(before.encode());old[path]=before.encode();new[path]=after.encode()
    (live/'config').mkdir();config=live/'config/db_connection.php'
    def guard(count):
        config.write_text("<?php class Database{function connect($p=false){return new D;}} class D{function query($s){return new R($s);}} class R{private $s;function __construct($s){$this->s=$s;}function fetch_assoc(){return strpos($this->s,'SHOW COLUMNS')===0?['Field'=>'destinatario_id']:['total'=>"+str(count)+"];}}",encoding='utf8')
    guard(0)
    protected=['public/javascript/agenda_reunion.js','public/javascript/recordatorios.js','public/javascript/agenda_fechas_referencia.js','app/services/AgendaReunionRepository.php']
    for path in protected:
        p=live/path;p.parent.mkdir(parents=True,exist_ok=True);p.write_bytes((ROOT/path).read_bytes())
    env=dict(os.environ,IMPE_CORREOS_PUBLIC=str(live),IMPE_CORREOS_BACKUPS=str(area/'backups'),PATH='C:/xampp/php'+os.pathsep+os.environ['PATH'])
    def run(*args,ok=True):
        r=subprocess.run([BASH,str(ROOT/'tools/publicar_destinatarios_agenda_hostinger.sh'),*args],env=env,capture_output=True,text=True,encoding='utf8')
        assert (r.returncode==0)==ok,(r.stdout,r.stderr);return r.stdout+r.stderr
    assert '7 archivo(s) pendientes' in run('--check') and all((live/p).read_bytes()==old[p] for p in CHANGED)
    assert all(not(live/p).exists() for p in NEW)
    run('--apply');assert all((live/p).read_bytes()==new[p] for p in CHANGED)
    assert all((live/p).read_bytes()==(ROOT/p).read_bytes() for p in NEW)
    assert 'sin cambios pendientes' in run('--apply')
    backup=next((area/'backups').iterdir());guard(1)
    assert 'SITIO INTACTO' in run('--restore',str(backup),ok=False) and all((live/p).read_bytes()==new[p] for p in CHANGED)
    config.write_text('<?php class Database{function connect($p=false){throw new RuntimeException;}}',encoding='utf8')
    assert 'SITIO INTACTO' in run('--restore',str(backup),ok=False)
    guard(0);run('--restore',str(backup));assert all((live/p).read_bytes()==old[p] for p in CHANGED)
    assert all(not(live/p).exists() for p in NEW)
    assert all((live/p).read_bytes()==(ROOT/p).read_bytes() for p in protected)
    (live/CHANGED[-1]).write_text('incompatible',encoding='utf8');snapshot={p:(live/p).read_bytes() for p in CHANGED}
    assert 'SITIO INTACTO' in run('--apply',ok=False) and all((live/p).read_bytes()==snapshot[p] for p in CHANGED)
    assert all(not(live/p).exists() for p in NEW),'Validar todos los fragmentos antes de instalar archivos'
print('OK: publicador LF/CRLF, cambios selectivos, idempotencia, respaldo/restauración y guardia contra difusión tras rollback; módulos ajenos intactos.')
