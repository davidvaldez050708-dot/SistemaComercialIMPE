"""Publicación selectiva: sitio simulado, cero acceso a base de datos."""
import base64,gzip,hashlib,json,pathlib,subprocess,sys,tempfile
R=pathlib.Path(__file__).resolve().parents[1]
spec=json.loads((R/'tools/ventas_audio_resultado_release.json').read_text(encoding='utf8'))
assert len(spec['files'])==5
unpack=lambda b:gzip.decompress(base64.b64decode(b))
for crlf in (False,True):
 with tempfile.TemporaryDirectory(prefix='cartera-deploy-') as folder:
  private=pathlib.Path(folder);site=private/'public_html';site.mkdir();(site/'index.php').write_bytes(b'<?php')
  before={};after={}
  for row in spec['files']:
   v=row['variants'][0];old=unpack(v['before']) if v['before'] else None;new=unpack(v['after'])
   assert old is None or hashlib.sha256(old).hexdigest()==v['before_sha']
   assert hashlib.sha256(new).hexdigest()==v['after_sha']
   assert (R/row['path']).read_bytes().replace(b'\r\n',b'\n')==new
   before[row['path']]=old.replace(b'\n',b'\r\n') if crlf and old is not None else old
   after[row['path']]=new.replace(b'\n',b'\r\n') if crlf and old is not None else new
   target=site/row['path'];target.parent.mkdir(parents=True,exist_ok=True)
   if old is not None:target.write_bytes(before[row['path']])
  dependencies_before={}
  for dependency in spec['dependencies']:
   version=dependency['compatible'][0] if crlf and dependency.get('compatible') else dependency
   data=unpack(version['content']);assert hashlib.sha256(data).hexdigest()==version['sha']
   if crlf:data=data.replace(b'\n',b'\r\n')
   dependencies_before[dependency['path']]=data
   target=site/dependency['path'];target.parent.mkdir(parents=True,exist_ok=True);target.write_bytes(data)
  phone=site/'app/helpers/TelefoniaFechaHoraHelper.php';phone.parent.mkdir(parents=True,exist_ok=True);phone.write_bytes((R/'app/helpers/TelefoniaFechaHoraHelper.php').read_bytes())
  phone_before=phone.read_bytes()
  capture=site/'app/views/telefonia/resultado_ventas_form.php';capture.parent.mkdir(parents=True,exist_ok=True);capture.write_bytes((R/'app/views/telefonia/resultado_ventas_form.php').read_bytes());capture_before=capture.read_bytes()
  report_result=site/'app/services/TelefoniaResultadoVentasService.php';report_result.parent.mkdir(parents=True,exist_ok=True);report_result.write_bytes((R/'app/services/TelefoniaResultadoVentasService.php').read_bytes());result_before=report_result.read_bytes()
  protected={}
  for name in ['telefonia_shell.js','telefonia_host.js','telefonia_ventas_shell.js','telefonia_ventas_resultados.js']:
   p=site/'public/javascript'/name;p.parent.mkdir(parents=True,exist_ok=True);p.write_bytes((R/'public/javascript'/name).read_bytes());protected[name]=p.read_bytes()
  styles={}
  for name in ['aliados_reportes.css','seguimiento_reportes_refinamiento.css','desempeno.css','ventas_actividad.css']:
   p=site/'public/css'/name;p.parent.mkdir(parents=True,exist_ok=True);p.write_bytes((R/'public/css'/name).read_bytes());styles[name]=p.read_bytes()
  sentinel=site/'storage/audio-test.ogg';sentinel.parent.mkdir(parents=True,exist_ok=True);sentinel.write_bytes(b'grabacion-inalterada')
  backups=private/'respaldos';command=[sys.executable,str(R/'tools/publicar_ventas_audio_resultado.py'),'--site',str(site),'--backup-root',str(backups)]
  def run(*args,ok=True):
   r=subprocess.run(command+list(args),capture_output=True,text=True,encoding='utf8')
   assert (r.returncode==0)==ok,r.stdout+r.stderr
   return r.stdout
  def unchanged():
   for p,data in before.items():assert ((site/p).read_bytes() if (site/p).exists() else None)==data,p
   assert sentinel.read_bytes()==b'grabacion-inalterada'
   assert phone.read_bytes()==phone_before
   assert all((site/'public/css'/name).read_bytes()==data for name,data in styles.items())
   assert capture.read_bytes()==capture_before
   assert report_result.read_bytes()==result_before
   assert all((site/'public/javascript'/name).read_bytes()==data for name,data in protected.items())
   assert all((site/p).read_bytes()==data for p,data in dependencies_before.items())
  run('--check');unchanged();assert not backups.exists()
  target=site/spec['files'][-1]['path'];original=target.read_bytes();target.write_bytes(original+b'\nPersonalizacion adicional')
  run('--apply',ok=False);target.write_bytes(original);unchanged();assert not backups.exists()
  run('--apply');assert all((site/p).read_bytes()==data for p,data in after.items())
  assert all((site/p).read_bytes()==data for p,data in dependencies_before.items())
  backup=next(backups.iterdir());assert run('--check').count('ACTUALIZADO:')==5
  run('--apply');assert len(list(backups.iterdir()))==1
  current=target.read_bytes();target.write_bytes(current+b'\nEdicion posterior');run('--restore',str(backup),ok=False);target.write_bytes(current)
  run('--restore',str(backup));unchanged();run('--restore',str(backup));unchanged()
print('PASS: cinco archivos de consulta privada de audio, versiones exactas, LF/CRLF, rechazo de personalizaciones desconocidas, check sin escrituras, respaldo y restauración exacta; flujo telefónico, formulario de captura y audio intactos.')
