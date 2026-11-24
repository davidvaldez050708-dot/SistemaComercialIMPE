from pathlib import Path
import os,json,tempfile,subprocess,hashlib
HERE=Path(__file__).resolve().parent;ROOT=HERE.parents[1]
OUT=ROOT/'output/agenda_marketing_20261124/deploy';OUT.mkdir(parents=True,exist_ok=True)
BASH='C:/Program Files/Git/bin/bash.exe' if os.name=='nt' else 'bash'
FILES=['app/services/AgendaMarketingNotificationService.php','public/javascript/convocatoria_notificaciones.js','app/controllers/ConvocatoriaNotificacionController.php']
checks=0
def check(value,message):
 global checks
 checks+=1;assert value,message
def posix(p):
 p=Path(p).resolve().as_posix();return '/'+p[0].lower()+p[2:] if len(p)>2 and p[1]==':' else p
def write(live,name,data):
 p=live/name;p.parent.mkdir(parents=True,exist_ok=True);p.write_bytes(data)
def snapshot(live):
 return {name:hashlib.sha256((live/name).read_bytes()).hexdigest() if (live/name).exists() else None for name in FILES}
def fixture(kind):
 live=Path(tempfile.mkdtemp(prefix=kind+'-',dir=OUT))/'live';live.mkdir()
 write(live,'index.php',b'<?php // fixture')
 for name in FILES[1:]:
  text=(HERE/'base'/Path(name).name).read_text(encoding='utf-8')
  if kind=='custom':text+='\n// PERSONALIZACION CONSERVADA\n'
  if kind=='crlf':text=text.replace('\n','\r\n')
  write(live,name,text.encode())
 write(live,'app/services/AgendaComentarioService.php',b'<?php // dependencia existente')
 write(live,'public/javascript/agenda_alertas.js',(HERE/'base/agenda_alertas.js').read_bytes())
 write(live,'public/audio/agenda-aviso.mp3',b'fixture de audio')
 if kind=='partial':write(live,FILES[1],(ROOT/'output/agenda_marketing_20261124/live'/FILES[1]).read_bytes())
 if kind=='conflict':
  p=live/FILES[1];p.write_text(p.read_text().replace('notifyUnseen(data.notificaciones || []);','notifyUnseen(miListaPersonalizada);'),encoding='utf-8')
 if kind=='missing':(live/'public/javascript/agenda_alertas.js').unlink()
 env=os.environ.copy();env['IMPE_AGENDA_PUBLIC']=posix(live);env['IMPE_AGENDA_BACKUPS']=posix(live.parent/'backups')
 return live,env
def run(env,mode,ok=True):
 p=subprocess.run([BASH,str(HERE/'publicar.sh'),mode],env=env,capture_output=True,text=True,encoding='utf-8',errors='replace')
 check((p.returncode==0)==ok,p.stdout+p.stderr)
 return p
for kind in ['base','custom','crlf','partial']:
 live,env=fixture(kind);before=snapshot(live)
 run(env,'--check');check(snapshot(live)==before,'CHECK no cambia el sitio')
 run(env,'--apply');check((live/FILES[0]).exists(),'Servicio instalado')
 check('IMPE_AGENDA_MARKETING_V1' in (live/FILES[1]).read_text(),'Conexión instalada')
 check("'recordatorios' => $agendaMarketing['recordatorios']" in (live/FILES[2]).read_text(),'Fuente de comentarios instalada')
 if kind=='custom':
  for name in FILES[1:]:check('PERSONALIZACION CONSERVADA' in (live/name).read_text(),'No sobreescribe cambios ajenos')
 after=snapshot(live);run(env,'--check');run(env,'--apply');check(after==snapshot(live),'Instalación repetible')
 print('OK: '+kind,flush=True)
for kind in ['conflict','missing']:
 live,env=fixture(kind);before=snapshot(live);run(env,'--check',False);run(env,'--apply',False);check(before==snapshot(live),'Incompatibilidad conserva todos los archivos')
live,env=fixture('rollback');before=snapshot(live)
shim=live.parent/'shim';shim.mkdir();(shim/'mv').write_text('#!/usr/bin/env bash\n/usr/bin/mv "$@"\nexit 1\n',encoding='utf-8',newline='\n');(shim/'mv').chmod(0o755)
(shim/'env.sh').write_text('export PATH="'+posix(shim)+':$PATH"\n',encoding='utf-8',newline='\n');env['BASH_ENV']=posix(shim/'env.sh')
run(env,'--apply',False);check(before==snapshot(live),'Restauración después de fallo de reemplazo')
print(json.dumps({'ok':True,'checks':checks,'escenarios':['base','personalizado','crlf','parcial','conflicto','dependencia','rollback']}))
