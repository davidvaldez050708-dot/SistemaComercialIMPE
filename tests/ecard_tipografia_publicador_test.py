from pathlib import Path
import subprocess,tempfile,os,re,hashlib,sys,shutil
root=Path(__file__).resolve().parents[1];bash,phpdir=sys.argv[1:3]
script=root/'tools/publicar_ecard_tipografia_hostinger.sh';file='app/services/EcardReunionService.php'
old=subprocess.check_output(['git','show','050428d9:'+file],cwd=root).replace(b'\r\n',b'\n')
assert hashlib.sha256(old).hexdigest()=='6b77dae491cb9ff3009d357b7e469683b2f41199c1fa4e489bdbff49c73e33fc'
new=(root/file).read_bytes().replace(b'\r\n',b'\n')
for eol in [b'\n',b'\r\n']:
 with tempfile.TemporaryDirectory(prefix='impe-ecard-solo-deploy-') as td:
  base=Path(td);live=base/'public_html';live.mkdir();target=live/file;target.parent.mkdir(parents=True)
  original=old.replace(b'\n',eol)+eol+b'// Personalizacion exterior conservada'
  expected=new.replace(b'\n',eol)+eol+b'// Personalizacion exterior conservada'
  target.write_bytes(original);(live/'index.php').write_bytes(b'<?php // ficticio')
  protected={}
  for f in ['config/db_connection.php','config/mail_central.local.php','app/services/AgendaReunionService.php','app/services/AgendaReunionRepository.php','app/services/AliadoNuevoNotificationService.php','app/controllers/AliadoController.php','app/controllers/ReminderController.php','app/views/layout/topbar.php','public/javascript/agenda_reunion.js','public/javascript/recordatorios.js','public/css/recordatorios.css','app/services/SeguimientoPostEnvioService.php']:
   p=live/f;p.parent.mkdir(parents=True,exist_ok=True)
   p.write_bytes(b'<?php class Database { public function __construct(){ throw new RuntimeException("BD PROHIBIDA EN PRUEBA"); }}' if f=='config/db_connection.php' else b'FICTICIO INTACTO')
   protected[p]=hashlib.sha256(p.read_bytes()).digest()
  assets=list(root.glob('public/img/ecards/templates/manuel-hq.part*.b64'))+[root/'public/img/ecards/manuel-porcayo.jpg']+[root/'vendor/dompdf/dompdf/lib/fonts'/f for f in ['DejaVuSans.ttf','DejaVuSans-Bold.ttf']]
  for src in assets:
   p=live/src.relative_to(root);p.parent.mkdir(parents=True,exist_ok=True);shutil.copyfile(src,p);protected[p]=hashlib.sha256(p.read_bytes()).digest()
  env=os.environ.copy();env['PATH']=phpdir+os.pathsep+env['PATH'];env['IMPE_CORREOS_PUBLIC']=live.as_posix();env['IMPE_CORREOS_BACKUPS']=(base/'backups').as_posix()
  def run(*args,ok=True):
   r=subprocess.run([bash,script.as_posix(),*args],cwd=root,env=env,capture_output=True,text=True,timeout=60)
   assert (r.returncode==0)==ok,r.stdout+r.stderr
   return r.stdout+r.stderr
  run('--probe',ok=False);assert target.read_bytes()==original and not (base/'backups').exists()
  run('--check');assert target.read_bytes()==original and not (base/'backups').exists()
  result=run('--apply');backup=re.search(r'^Respaldo: (.+)$',result,re.M)[1];assert target.read_bytes()==expected
  assert 'ya aplicado' in run('--apply');assert len(list((base/'backups').iterdir()))==1
  tmp_before=set(Path(tempfile.gettempdir()).glob('impe-ecard-tipo-*'))
  result=run('--probe');assert 'VERSION_ECARD=20261026-tipografia-1' in result and 'ALTURA_FECHA_PX=54' in result and 'ALTURA_HORA_PX=34' in result
  assert set(Path(tempfile.gettempdir()).glob('impe-ecard-tipo-*'))==tmp_before and not (live/'storage').exists()
  # Un fallo de plantilla después de preparar el temporal también debe limpiarlo.
  asset=next(live.glob('public/img/ecards/templates/manuel-hq.part*.b64'));saved=asset.read_bytes();asset.write_bytes(b'')
  run('--probe',ok=False);assert set(Path(tempfile.gettempdir()).glob('impe-ecard-tipo-*'))==tmp_before;asset.write_bytes(saved)
  target.write_bytes(expected+eol+b'// Cambio posterior');snapshot=target.read_bytes();run('--restore',backup,ok=False);assert target.read_bytes()==snapshot
  target.write_bytes(expected);run('--restore',backup);assert target.read_bytes()==original
  assert 'ya aplicado o restaurado' in run('--restore',backup)
  target.write_bytes(original.replace(b'20260920-14',b'ECARD-PERSONALIZADA'));snapshot=target.read_bytes();run('--apply',ok=False);assert target.read_bytes()==snapshot
  assert all(hashlib.sha256(p.read_bytes()).digest()==h for p,h in protected.items())
  assert not (live/'storage').exists()
  print('OK: '+('CRLF' if eol==b'\r\n' else 'LF')+', base exacta Hostinger, un solo archivo, check sin escritura, respaldo/restore, idempotencia, rechazo seguro, probe sin BD ni caché pública y temporales retirados incluso al fallar.',flush=True)
