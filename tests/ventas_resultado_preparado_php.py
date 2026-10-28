"""Render de las vistas reales con sesión/agenda sintéticas; nunca conecta al CRM."""
import os,pathlib,shutil,socket,subprocess,tempfile,time,urllib.request
R=pathlib.Path(__file__).resolve().parents[1]
PHP=os.environ.get('PHP_BINARY','php')
with tempfile.TemporaryDirectory(prefix='impe-resultado-preparado-') as folder:
 P=pathlib.Path(folder)
 for path in ['app/views/layout/dashboard_footer.php','app/views/telefonia/resultado_ventas_form.php','app/services/TelefoniaResultadoVentasService.php','public/javascript/telefonia_ventas_resultados.js']:
  target=P/path;target.parent.mkdir(parents=True,exist_ok=True);shutil.copyfile(R/path,target)
 shutil.copytree(R/'public/css',P/'public/css')
 for name in ['telefonia_ventas_observaciones.js']:
  (P/'public/javascript'/name).write_bytes(subprocess.check_output(['git','show','008f1417:public/javascript/'+name],cwd=R))
 (P/'app/views/layout/profile_photo_modal.php').write_text('<?php',encoding='utf8')
 (P/'app/services/TelefoniaExtensionService.php').write_text('<?php class TelefoniaExtensionService {}',encoding='utf8')
 (P/'app/services/TelefoniaContactosService.php').write_text("<?php class TelefoniaContactosService { public function listar($id) { $GLOBALS['agenda_usuario']=$id; if($id!==15)throw new RuntimeException('Propietario incorrecto');return [['id'=>1,'nombre'=>'Agenda de prueba','telefono'=>'5555555555']]; } public static function tokenFormulario(){return 'token-prueba';} }",encoding='utf8')
 (P/'config').mkdir();(P/'config/db_connection.php').write_text("<?php class Database { public function connect(){throw new RuntimeException('Prohibido conectar a una base real');} }",encoding='utf8')
 php='''<?php
if(PHP_SAPI==='cli')$_GET=json_decode($argv[1]??'{}',true);
define('ROOT_PATH',__DIR__);define('BASE_URL','http://'.($_SERVER['HTTP_HOST']??'127.0.0.1').'/');
$_SESSION=['usuario_id'=>15,'rol_id'=>(int)($_GET['rol']??3),'csrf_token'=>'token-prueba','requiere_cambio_password'=>(int)($_GET['password']??0)];
function tienePermiso($p){return empty($_GET['sin_permiso']);}
?><!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="stylesheet" href="/assets/bootstrap.css"><link rel="stylesheet" href="/public/css/global.css"><link rel="stylesheet" href="/public/css/dashboard.css"><link rel="stylesheet" href="/public/css/responsive_sistema.css"><script>window.IMPE_CURRENT_USER_ID=15;</script></head><body><div class="admin-shell"><main><section><h1>Inicio de prueba</h1>
<?php
if(!empty($_GET['historial'])){
 require __DIR__.'/app/services/TelefoniaContactosService.php';require __DIR__.'/app/services/TelefoniaResultadoVentasService.php';
 $contactosVentas=[];echo '<div data-sales-history data-detail-url="/detalle" data-save-url="/guardar">';require __DIR__.'/app/views/telefonia/resultado_ventas_form.php';echo '</div>';
}
require __DIR__.'/app/views/layout/dashboard_footer.php';
echo '<!-- agenda_usuario:'.($GLOBALS['agenda_usuario']??'ninguno').' -->';
'''
 (P/'index.php').write_text(php,encoding='utf8')
 for params,count in [('{}',1),('{"historial":1}',1),('{"rol":1}',0),('{"rol":4}',0),('{"sin_permiso":1}',0)]:
  run=subprocess.run([PHP,str(P/'index.php'),params],capture_output=True,text=True,encoding='utf8',check=True)
  assert not run.stderr,run.stderr
  assert run.stdout.count('id="modalResultadoVenta"')==count,(params,'Duplicó o perdió el formulario')
  if params=='{}':
   assert 'agenda_usuario:15' in run.stdout
   assert 'CANCELADA_POR_ASESOR' in run.stdout and 'data-requires-connection="0"' in run.stdout
  if params=='{"historial":1}':assert 'data-sales-global-result' not in run.stdout
 print('PASS: footer real prepara un solo formulario para el asesor autorizado, conserva el del historial y consulta sólo su agenda.')
 if os.environ.get('IMPE_BROWSER_TEST')=='1':
  (P/'assets').mkdir()
  for path,url in [('bootstrap.css','https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css'),('bootstrap.js','https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js')]:
   (P/'assets'/path).write_bytes(urllib.request.urlopen(url,timeout=30).read())
  with socket.socket() as s:s.bind(('127.0.0.1',0));port=s.getsockname()[1]
  server=subprocess.Popen([PHP,'-S','127.0.0.1:'+str(port),'-t',str(P)],stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL,creationflags=0x08000000 if os.name=='nt' else 0)
  try:
   for _ in range(30):
    try:urllib.request.urlopen('http://127.0.0.1:'+str(port)+'/',timeout=1);break
    except OSError:time.sleep(.1)
   env=dict(os.environ,IMPE_TEST_URL='http://127.0.0.1:'+str(port),IMPE_TEST_FIXTURE=str(P))
   subprocess.run([os.environ.get('NODE_BINARY','node'),str(R/'tests/ventas_resultado_preparado_browser.cjs')],env=env,check=True)
  finally:server.terminate();server.wait(timeout=10)
