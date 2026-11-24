"""Prueba instalación real de archivos sobre fixtures, con BD simulada sin red."""
from pathlib import Path
import hashlib
import json
import os
import subprocess
import tempfile

HERE = Path(__file__).resolve().parent
ROOT = HERE.parents[1]
OUT = ROOT / 'output/agenda_destinatarios_20261124/publicador-multi4'
OUT.mkdir(parents=True, exist_ok=True)
BASH = os.environ.get('AGENDA_BASH', 'C:/Program Files/Git/bin/bash.exe' if os.name == 'nt' else 'bash')
FILES = list(json.loads((HERE/'instalacion.json').read_text(encoding='utf-8')))
checks = 0

def check(value, message):
    global checks
    checks += 1
    assert value, message

def posix(path):
    text = Path(path).resolve().as_posix()
    return '/'+text[0].lower()+text[2:] if len(text)>2 and text[1]==':' else text

def put(live, name, data):
    dest=live/name; dest.parent.mkdir(parents=True,exist_ok=True);dest.write_bytes(data)

def contents(live):
    return {name:hashlib.sha256((live/name).read_bytes()).hexdigest() if (live/name).exists() else None for name in FILES}

def fixture(kind='base', fail_db=False):
    folder=Path(tempfile.mkdtemp(prefix=kind+'-',dir=OUT));live=folder/'live';live.mkdir()
    put(live,'index.php',b'<?php // Fixture aislada\n')
    for name in FILES:
        base=HERE/'base_hostinger'/name
        if kind in ['anterior','multi3']:
            revision='85b0c915' if kind=='multi3' else 'a2e67b1d'
            proc=subprocess.run(['git','show',f'{revision}:tools/agenda_destinatarios_20261124/files/{name}'],cwd=ROOT,capture_output=True)
            if proc.returncode==0: put(live,name,proc.stdout)
        elif base.exists():
            text=base.read_text(encoding='utf-8')
            put(live,name,text.replace('\n','\r\n').encode() if kind=='crlf' else text.encode())
    if kind=='mixto':
        for name in [FILES[0],FILES[1],FILES[-1]]:put(live,name,(HERE/'files'/name).read_bytes())
    if kind=='ajeno':
        name=FILES[1];put(live,name,(live/name).read_bytes()+b'\n// Cambio ajeno posterior al diagnostico\n')
    php='''<?php
class Database { public function connect($p=false) { return new class {
public function query($sql) {
file_put_contents(__DIR__.'/consultas.log',$sql."\\n",FILE_APPEND);
if (FAIL_DB && str_contains($sql,'CREATE TABLE')) throw new RuntimeException('Fallo de BD simulado');
return true;
}
public function close() {}
};}}
'''.replace('FAIL_DB','true' if fail_db else 'false')
    put(live,'config/db_connection.php',php.encode())
    env=os.environ.copy();env['IMPE_AGENDA_PUBLIC']=posix(live);env['IMPE_AGENDA_BACKUPS']=posix(folder/'backups')
    return live,env

def run(live,env,mode,ok=True):
    p=subprocess.run([BASH,str(HERE/'publicar.sh'),mode],cwd=ROOT,env=env,capture_output=True,text=True,encoding='utf-8',errors='replace')
    check((p.returncode==0)==ok,p.stdout+p.stderr)
    return p

for kind in ['base','anterior','multi3','mixto','crlf']:
    live,env=fixture(kind);before=contents(live)
    run(live,env,'--check')
    check(contents(live)==before,'CHECK no modifica archivos '+kind)
    check('CREATE TABLE' not in (live/'config/consultas.log').read_text(),'CHECK no ejecuta migración')
    result=run(live,env,'--apply')
    check('selector de varios usuarios con diseño original multi4' in result.stdout,'APPLY comprueba selector instalado')
    for name in FILES:
        check((live/name).read_text(encoding='utf-8')==(HERE/'files'/name).read_text(encoding='utf-8'),'Archivo instalado '+kind+' '+name)
    after=contents(live)
    run(live,env,'--check');run(live,env,'--apply')
    check(contents(live)==after,'Repetición idempotente '+kind)
    check(len(list((live.parent/'backups').glob('*/archivos-anteriores.tar.gz')))==1,'Respaldo único '+kind)
    print('OK: '+kind,flush=True)

live,env=fixture('ajeno');before=contents(live)
run(live,env,'--check',False);run(live,env,'--apply',False)
check(contents(live)==before,'Conserva código ajeno cuando no reconoce la versión')
live,env=fixture(fail_db=True);before=contents(live)
run(live,env,'--apply',False)
check(contents(live)==before,'Un fallo de BD no cambia el selector ni los servicios')

# Inyectar un fallo después del primer reemplazo para ejercitar la restauración.
live,env=fixture();before=contents(live)
shim=live.parent/'shim';shim.mkdir()
(shim/'mv').write_text('#!/usr/bin/env bash\n/usr/bin/mv "$@"\nexit 1\n',encoding='utf-8',newline='\n')
(shim/'mv').chmod(0o755)
(shim/'env.sh').write_text('export PATH="'+posix(shim)+':$PATH"\n',encoding='utf-8',newline='\n')
env['BASH_ENV']=posix(shim/'env.sh')
run(live,env,'--apply',False)
check(contents(live)==before,'Restaura los archivos si falla el primer reemplazo')

print(json.dumps({'ok':True,'checks':checks,'escenarios':['base','anterior','multi3','mixto','crlf','ajeno','fallo_bd','rollback']},ensure_ascii=False))
