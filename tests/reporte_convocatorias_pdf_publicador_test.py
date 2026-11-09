"""Despliegue reversible en sitios ficticios; nunca usa producción ni BD."""
from pathlib import Path
import subprocess,tempfile,os,re,hashlib,shutil
import sys
root=Path(__file__).resolve().parents[1];bash,phpdir=sys.argv[1:3]
script=root/'tools/publicar_pdf_convocatorias_hostinger.sh'
file='app/services/ReporteConvocatoriaPdfService.php';renderer='app/services/ReporteConvocatoriaDompdfService.php'
old=subprocess.check_output(['git','show','adf34ab1:'+file],cwd=root).replace(b'\r\n',b'\n')
new=(root/file).read_bytes().replace(b'\r\n',b'\n')
helper=(root/renderer).read_bytes().replace(b'\r\n',b'\n')
for eol in [b'\n',b'\r\n']:
 with tempfile.TemporaryDirectory(prefix='impe-pdf-deploy-') as td:
  base=Path(td);live=base/'public_html';live.mkdir();target=live/file;target.parent.mkdir(parents=True);extra=live/renderer
  original=old.replace(b'\n',eol)+eol+b'// Personalizacion exterior conservada'
  expected=new.replace(b'\n',eol)+eol+b'// Personalizacion exterior conservada'
  target.write_bytes(original);(live/'index.php').write_bytes(b'<?php // ficticio')
  (live/'vendor').mkdir();(live/'vendor/autoload.php').write_text('<?php require '+repr((root/'vendor/autoload.php').as_posix())+';',encoding='utf-8')
  template='storage/templates/reportes/seguimiento_vinculacion/Plantilla_Reporte_Seguimiento.docx'
  p=live/template;p.parent.mkdir(parents=True);shutil.copyfile(root/template,p)
  shutil.copyfile(root/'app/services/ReporteNombreArchivoService.php',target.parent/'ReporteNombreArchivoService.php')
  protected={}
  for f in [template,'app/services/ReporteNombreArchivoService.php','app/services/OficioDocxPdfService.php','app/services/ReporteAliadosPdfService.php','app/services/DesempenoPdfService.php','config/mail_central.local.php','config/db_connection.php','app/views/reportes/index.php','app/controllers/ConvocatoriaReporteController.php']:
   p=live/f;p.parent.mkdir(parents=True,exist_ok=True)
   if not p.exists():p.write_bytes(b'FICTICIO INTACTO')
   protected[p]=hashlib.sha256(p.read_bytes()).digest()
  env=os.environ.copy();env['PATH']=phpdir+os.pathsep+env['PATH'];env['IMPE_CORREOS_PUBLIC']=live.as_posix();env['IMPE_CORREOS_BACKUPS']=(base/'backups').as_posix()
  def run(*args,ok=True):
   r=subprocess.run([bash,script.as_posix(),*args],cwd=root,env=env,capture_output=True,text=True,timeout=45)
   assert (r.returncode==0)==ok,r.stdout+r.stderr
   return r.stdout
  run('--check');assert target.read_bytes()==original and not extra.exists() and not (base/'backups').exists()
  result=run('--apply');backup=re.search(r'^Respaldo: (.+)$',result,re.M)[1]
  if target.read_bytes()!=expected or extra.read_bytes()!=helper:
   import difflib
   print('SERVICE', ''.join(difflib.unified_diff(expected.decode().splitlines(True),target.read_text(encoding='utf-8').splitlines(True)))[:1500])
   print('HELPER',hashlib.sha256(extra.read_bytes()).hexdigest(),hashlib.sha256(helper).hexdigest())
   raise AssertionError('Resultado distinto del codigo revisado')
  assert 'ya aplicado' in run('--apply')
  if eol==b'\n':assert 'DOMPDF_PHP' in run('--probe')
  extra.write_bytes(helper+b'// posterior');run('--restore',backup,ok=False);assert target.read_bytes()==expected
  extra.write_bytes(helper);run('--restore',backup);assert target.read_bytes()==original and not extra.exists()
  assert 'ya aplicado o restaurado' in run('--restore',backup)
  extra.write_bytes(b'<?php // personalizado');run('--apply',ok=False);assert target.read_bytes()==original and extra.read_bytes()==b'<?php // personalizado'
  extra.unlink()
  target.write_bytes(original.replace(b'$directorioTemporal =',b'$otroTemporal =',1));run('--check',ok=False);assert not extra.exists()
  assert all(hashlib.sha256(p.read_bytes()).digest()==h for p,h in protected.items())
  print('OK: '+('CRLF' if eol==b'\r\n' else 'LF')+', check/apply/restore, idempotencia, ausencia original restaurada, personalizaciones y archivos protegidos intactos.',flush=True)
