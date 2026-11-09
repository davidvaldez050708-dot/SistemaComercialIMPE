"""Publicador en sitio ficticio: dos archivos visuales, sin BD ni envíos."""
from pathlib import Path
import hashlib,os,re,subprocess,sys,tempfile
root=Path(__file__).resolve().parents[1]
bash,phpdir=sys.argv[1:3]
script=root/'tools/publicar_preview_convocatoria_correo_hostinger.sh'
files=['app/views/correos/index.php','public/css/correos_marketing.css']
old=[subprocess.check_output(['git','show','3ea6d960:'+f],cwd=root).replace(b'\r\n',b'\n') for f in files]
new=[(root/f).read_bytes().replace(b'\r\n',b'\n') for f in files]
changed=subprocess.check_output(['git','diff','--name-only','3ea6d960','--','app','public','config'],cwd=root,text=True).splitlines()
assert changed==files,changed
for eol in [b'\n',b'\r\n']:
 with tempfile.TemporaryDirectory(prefix='impe-preview-test-') as td:
  base=Path(td);live=base/'public_html';live.mkdir();targets=[live/f for f in files]
  originals=[b'<!-- Personalizacion previa -->'+eol+old[0].replace(b'\n',eol)+eol+b'<!-- Fin local -->',old[1].replace(b'\n',eol)+eol+b'/* Estilos locales conservados */']
  expected=[b'<!-- Personalizacion previa -->'+eol+new[0].replace(b'\n',eol)+eol+b'<!-- Fin local -->',new[1].replace(b'\n',eol)+eol+b'/* Estilos locales conservados */']
  for p,s in zip(targets,originals):p.parent.mkdir(parents=True,exist_ok=True);p.write_bytes(s)
  (live/'index.php').write_text('<?php // sitio ficticio')
  protected={}
  for f in ['config/mail_central.local.php','config/password_reset_mail.local.php','app/services/CorreoHostingerApiService.php','app/services/CorreoConvocatoriaContenidoService.php','public/javascript/correos_marketing.js','public/javascript/correo_convocatorias.js','app/controllers/CorreoMarketingController.php','app/models/CorreoMarketingModel.php','public/css/global.css']:
   p=live/f;p.parent.mkdir(parents=True,exist_ok=True);p.write_bytes(b'FICTICIO INTACTO');protected[p]=hashlib.sha256(p.read_bytes()).digest()
  env=os.environ.copy();env['PATH']=phpdir+os.pathsep+env['PATH'];env['IMPE_CORREOS_PUBLIC']=live.as_posix();env['IMPE_CORREOS_BACKUPS']=(base/'backups').as_posix()
  def run(*args,ok=True):
   r=subprocess.run([bash,script.as_posix(),*args],cwd=root,env=env,capture_output=True,text=True,timeout=45)
   assert (r.returncode==0)==ok,r.stdout+r.stderr
   return r.stdout
  def contents():return [p.read_bytes() for p in targets]
  run('--check');assert contents()==originals;assert not (base/'backups').exists()
  result=run('--apply');backup=re.search(r'^Respaldo: (.+)$',result,re.M)[1];assert contents()==expected
  assert 'ya aplicado' in run('--apply');assert len(list((base/'backups').iterdir()))==1
  targets[1].write_bytes(expected[1]+eol+b'/* Cambio posterior */');snapshot=contents()
  run('--restore',backup,ok=False);assert contents()==snapshot
  targets[1].write_bytes(expected[1]);run('--restore',backup);assert contents()==originals
  assert 'ya aplicado o restaurado' in run('--restore',backup)
  run('--restore',str(base),ok=False)
  # Si el CSS es incompatible, ni siquiera se instala la vista ya preparada.
  targets[1].write_bytes(originals[1].replace(b'/* Redactar correo: escala',b'/* Otra escala'))
  snapshot=contents();run('--apply',ok=False);assert contents()==snapshot;assert len(list((base/'backups').iterdir()))==1
  targets[1].write_bytes(originals[1])
  # Completar un estado parcial, conservando el archivo ya actualizado.
  targets[0].write_bytes(expected[0]);snapshot=contents()
  result=run('--apply');partial_backup=re.search(r'^Respaldo: (.+)$',result,re.M)[1];assert contents()==expected
  run('--restore',partial_backup);assert contents()==snapshot
  # Un bloque de vista alterado detiene toda la publicación.
  targets[0].write_bytes(originals[0].replace(b'data-convocatoria-preview',b'data-convocatoria-preview-local'))
  snapshot=contents();run('--check',ok=False);assert contents()==snapshot
  assert all(hashlib.sha256(p.read_bytes()).digest()==h for p,h in protected.items())
  assert sorted(p.relative_to(live).as_posix() for p in live.rglob('*') if p.is_file())==sorted(files+['index.php']+[p.relative_to(live).as_posix() for p in protected])
  print('OK: '+('CRLF' if eol==b'\r\n' else 'LF')+'; check sin escritura, dos parches exactos, personalizaciones conservadas, idempotencia, restauracion y estado parcial; servicios/JS intactos.',flush=True)
