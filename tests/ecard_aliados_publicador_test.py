from pathlib import Path
import subprocess,tempfile,os,re,hashlib,sys,shutil
root=Path(__file__).resolve().parents[1];bash,phpdir=sys.argv[1:3]
script=root/'tools/publicar_ecard_aliados_hostinger.sh'
files=['app/services/EcardReunionService.php','app/controllers/ReminderController.php','app/controllers/AliadoController.php','app/views/layout/topbar.php','app/services/AliadoNuevoNotificationService.php','tools/configurar_avisos_aliados.php']
old=[subprocess.check_output(['git','show','050428d9:'+f],cwd=root).replace(b'\r\n',b'\n') for f in files[:4]]+[None,None]
new=[(root/f).read_bytes().replace(b'\r\n',b'\n') for f in files]
for eol in [b'\n',b'\r\n']:
 with tempfile.TemporaryDirectory(prefix='impe-ecard-aliados-deploy-') as td:
  base=Path(td);live=base/'public_html';live.mkdir();targets=[live/f for f in files]
  originals=[s.replace(b'\n',eol)+eol+b'// Personalizacion exterior conservada' if s is not None else None for s in old]
  expected=[s.replace(b'\n',eol)+eol+b'// Personalizacion exterior conservada' if i<4 else s for i,s in enumerate(new)]
  for p,s in zip(targets,originals):
   p.parent.mkdir(parents=True,exist_ok=True)
   if s is not None:p.write_bytes(s)
  (live/'index.php').write_bytes(b'<?php // ficticio')
  protected={}
  for file in ['config/db_connection.php','config/mail_central.local.php','app/services/AgendaReunionService.php','app/services/AgendaReunionRepository.php','public/javascript/recordatorios.js','public/css/recordatorios.css','app/services/SeguimientoPostEnvioService.php']:
   p=live/file;p.parent.mkdir(parents=True,exist_ok=True);p.write_bytes(b'FICTICIO INTACTO');protected[p]=hashlib.sha256(p.read_bytes()).digest()
  for font in ['DejaVuSans.ttf','DejaVuSans-Bold.ttf']:
   file='vendor/dompdf/dompdf/lib/fonts/'+font;p=live/file;p.parent.mkdir(parents=True,exist_ok=True);shutil.copyfile(root/file,p);protected[p]=hashlib.sha256(p.read_bytes()).digest()
  env=os.environ.copy();env['PATH']=phpdir+os.pathsep+env['PATH'];env['IMPE_CORREOS_PUBLIC']=live.as_posix();env['IMPE_CORREOS_BACKUPS']=(base/'backups').as_posix()
  def run(*args,ok=True):
   r=subprocess.run([bash,script.as_posix(),*args],cwd=root,env=env,capture_output=True,text=True,timeout=60)
   assert (r.returncode==0)==ok,r.stdout+r.stderr
   return r.stdout
  def state():return [p.read_bytes() if p.exists() else None for p in targets]
  run('--check');assert state()==originals and not (base/'backups').exists()
  result=run('--apply');backup=re.search(r'^Respaldo: (.+)$',result,re.M)[1];assert state()==expected
  assert 'ya aplicado' in run('--apply');assert len(list((base/'backups').iterdir()))==1
  targets[2].write_bytes(expected[2]+eol+b'// Cambio posterior');snapshot=state();run('--restore',backup,ok=False);assert state()==snapshot
  targets[2].write_bytes(expected[2]);run('--restore',backup);assert state()==originals
  assert 'ya aplicado o restaurado' in run('--restore',backup)
  targets[0].write_bytes(originals[0].replace(b'20260920-14',b'ECARD-PERSONALIZADA'))
  snapshot=state();run('--apply',ok=False);assert state()==snapshot
  for p,s in zip(targets,originals):
   if s is not None:p.write_bytes(s)
  targets[0].write_bytes(expected[0]);snapshot=state()
  result=run('--apply');bp=re.search(r'^Respaldo: (.+)$',result,re.M)[1];assert state()==expected
  run('--restore',bp);assert state()==snapshot
  # Un archivo nuevo ajeno no se puede reemplazar.
  targets[4].write_bytes(b'<?php // servicio local distinto');snapshot=state();run('--apply',ok=False);assert state()==snapshot
  assert all(hashlib.sha256(p.read_bytes()).digest()==h for p,h in protected.items())
  assert not any(p.name.endswith('.impe-ecard-aliados') for p in live.rglob('*'))
  print('OK: '+('CRLF' if eol==b'\r\n' else 'LF')+', check sin escritura, seis archivos, contenido exterior conservado, respaldo/restore exacto, archivos nuevos retirados, idempotencia, actualización parcial y rechazo de personalizaciones; sin SQL.',flush=True)
