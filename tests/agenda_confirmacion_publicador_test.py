from pathlib import Path
import subprocess,tempfile,os,re,hashlib,sys
root=Path(__file__).resolve().parents[1];bash,phpdir=sys.argv[1:3]
script=root/'tools/publicar_agenda_confirmacion_campana_hostinger.sh'
files=['app/services/AgendaReunionRepository.php','app/services/AgendaReunionService.php','app/services/ReminderMeetingConfirmationService.php']
old=[subprocess.check_output(['git','show','12d9c62d:'+f],cwd=root).replace(b'\r\n',b'\n') for f in files]
new=[(root/f).read_bytes().replace(b'\r\n',b'\n') for f in files]
assert subprocess.check_output(['git','diff','--name-only','12d9c62d','--','app','public','config'],cwd=root,text=True).splitlines()==files
for eol in [b'\n',b'\r\n']:
 with tempfile.TemporaryDirectory(prefix='impe-agenda-deploy-') as td:
  base=Path(td);live=base/'public_html';live.mkdir();targets=[live/f for f in files]
  originals=[s.replace(b'\n',eol)+eol+b'// Personalizacion exterior conservada' for s in old]
  expected=[s.replace(b'\n',eol)+eol+b'// Personalizacion exterior conservada' for s in new]
  for p,s in zip(targets,originals):p.parent.mkdir(parents=True,exist_ok=True);p.write_bytes(s)
  (live/'index.php').write_bytes(b'<?php // ficticio')
  protected={}
  for file in ['config/db_connection.php','config/mail_central.local.php','app/services/CorreoHostingerApiService.php','app/services/ReprogramacionReunionService.php','app/helpers/PermissionHelper.php','public/javascript/agenda_reunion.js','app/controllers/ReminderController.php','app/services/ReporteConvocatoriaPdfService.php']:
   p=live/file;p.parent.mkdir(parents=True,exist_ok=True);p.write_bytes(b'FICTICIO INTACTO');protected[p]=hashlib.sha256(p.read_bytes()).digest()
  env=os.environ.copy();env['PATH']=phpdir+os.pathsep+env['PATH'];env['IMPE_CORREOS_PUBLIC']=live.as_posix();env['IMPE_CORREOS_BACKUPS']=(base/'backups').as_posix()
  def run(*args,ok=True):
   r=subprocess.run([bash,script.as_posix(),*args],cwd=root,env=env,capture_output=True,text=True,timeout=40)
   assert (r.returncode==0)==ok,r.stdout+r.stderr
   return r.stdout
  def state():return [p.read_bytes() for p in targets]
  run('--check');assert state()==originals and not (base/'backups').exists()
  result=run('--apply');backup=re.search(r'^Respaldo: (.+)$',result,re.M)[1];assert state()==expected
  assert 'ya aplicado' in run('--apply');assert len(list((base/'backups').iterdir()))==1
  targets[2].write_bytes(expected[2]+eol+b'// Cambio posterior');snapshot=state();run('--restore',backup,ok=False);assert state()==snapshot
  targets[2].write_bytes(expected[2]);run('--restore',backup);assert state()==originals
  assert 'ya aplicado o restaurado' in run('--restore',backup)
  # Si un método no coincide, no se instala ninguno de los archivos preparados.
  targets[1].write_bytes(originals[1].replace(b'La solicitud fue atendida por otro usuario.',b'Mensaje local distinto.'))
  snapshot=state();run('--apply',ok=False);assert state()==snapshot
  for p,s in zip(targets,originals):p.write_bytes(s)
  targets[0].write_bytes(expected[0]);snapshot=state()
  result=run('--apply');backup_partial=re.search(r'^Respaldo: (.+)$',result,re.M)[1];assert state()==expected
  run('--restore',backup_partial);assert state()==snapshot
  assert all(hashlib.sha256(p.read_bytes()).digest()==h for p,h in protected.items())
  assert sorted(p.relative_to(live).as_posix() for p in live.rglob('*') if p.is_file())==sorted(files+['index.php']+[p.relative_to(live).as_posix() for p in protected])
  print('OK: '+('CRLF' if eol==b'\r\n' else 'LF')+', check sin escritura, tres parches exactos, personalizaciones, respaldo/restore, idempotencia, actualización parcial y rechazo seguro; sin SQL.',flush=True)
