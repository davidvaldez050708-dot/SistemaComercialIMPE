"""Renderiza vistas reales y dos PDF con datos sintéticos exportados por la prueba DB."""
import io,json,os,pathlib,re,shutil,socket,subprocess,tarfile,tempfile,time,urllib.request
R=pathlib.Path(__file__).resolve().parents[1];OUT=R/'tmp/consulta-qa';PHP=os.environ.get('PHP_BINARY','php')
with tempfile.TemporaryDirectory(prefix='impe-consulta-qa-') as folder:
 P=pathlib.Path(folder)
 with tarfile.open(fileobj=io.BytesIO(subprocess.check_output(['git','archive','08451cb7','app'],cwd=R))) as archive:archive.extractall(P,filter='data')
 paths=['app/services/TelefoniaResultadoVentasCatalogo.php','app/services/TelefoniaResultadoVentasService.php','app/services/TelefoniaVentasLlamadasService.php','app/services/VentasActividadMetricas.php','app/services/VentasActividadPdfView.php','app/services/VentasDesempenoPdfView.php','app/services/ReporteNombreArchivoService.php','app/views/reportes/ventas_actividad.php','app/views/telefonia/ventas_llamadas.php','app/views/telefonia/resultado_ventas_form.php','public/javascript/supervision_llamadas.js']
 for path in paths:target=P/path;target.parent.mkdir(parents=True,exist_ok=True);shutil.copyfile(R/path,target)
 refs={'app/views/telefonia/supervision_llamadas.php':'008f1417','app/views/telefonia/ventas_pendientes_resumen.php':'77d57ada','app/views/telefonia/ventas_paginacion.php':'8dcab048'}
 for path,rev in refs.items():
  target=P/path;target.parent.mkdir(parents=True,exist_ok=True);target.write_bytes(subprocess.check_output(['git','show',rev+':'+path],cwd=R))
 for path,rev in {'app/services/TelefoniaLimpiezaGuard.php':'6ba06aa6','app/helpers/VentasPendientesPolicy.php':'77d57ada'}.items():
  (P/path).write_bytes(subprocess.check_output(['git','show',rev+':'+path],cwd=R))
 shutil.copytree(R/'public/css',P/'public/css');shutil.copytree(R/'public/javascript',P/'public/javascript',dirs_exist_ok=True);(P/'config').mkdir()
 for name in ['ventas_actividad.js','reportes_pdf_descarga.js']:
  (P/'public/javascript'/name).write_bytes(subprocess.check_output(['git','show','77d57ada:public/javascript/'+name],cwd=R))
 for name,rev in [('ventas_actividad.css','77d57ada')]:
  (P/'public/css'/name).write_bytes(subprocess.check_output(['git','show',rev+':public/css/'+name],cwd=R))
 (P/'config/db_connection.php').write_text("<?php class Database {public function connect(){throw new RuntimeException('Sin acceso a producción');}}",encoding='utf8')
 # Avatar/navegación no son parte del cambio ni de estas comprobaciones.
 (P/'app/helpers/AvatarHelper.php').write_text('''<?php function renderAvatarUsuario(...$args){return '<span aria-hidden="true">●</span>';}''',encoding='utf8')
 shutil.copyfile(OUT/'datos.json',P/'datos.json')
 css_source=(R/'app/services/DesempenoPdfService.php').read_text(encoding='utf8')
 css=re.search(r'private function css\(\)\s*\{\s*return (.*?);\s*\}',css_source,re.S).group(1)
 (P/'css_pdf.php').write_text('<?php return '+css+';',encoding='utf8')
 (P/'assets').mkdir();(P/'assets/bootstrap.css').write_bytes(urllib.request.urlopen('https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css',timeout=30).read())
 with socket.socket() as sock:sock.bind(('127.0.0.1',0));port=sock.getsockname()[1]
 base='http://127.0.0.1:'+str(port)+'/'
 script='''<?php
error_reporting(E_ALL);date_default_timezone_set('America/Mexico_City');
define('ROOT_PATH',__DIR__);define('BASE_URL',__BASE__);
require __DIR__.'/app/services/VentasActividadPdfView.php';require __DIR__.'/app/services/VentasDesempenoPdfView.php';
$_SESSION=['usuario_id'=>1,'rol_id'=>1,'csrf_token'=>'prueba'];$_GET=[];$fixture=json_decode(file_get_contents(__DIR__.'/datos.json'),true,512,JSON_THROW_ON_ERROR);$d=$fixture['actividad'];
if(($argv[1]??'')==='pdf'){
 require __AUTOLOAD__;$css=require __DIR__.'/css_pdf.php';
 foreach(['reporte'=>new VentasActividadPdfView(),'desempeno'=>new VentasDesempenoPdfView()] as $name=>$view){
  $html=$view->html($d,'',$css);$pdf=new Dompdf\\Dompdf(['isRemoteEnabled'=>false,'defaultFont'=>'DejaVu Sans']);$pdf->loadHtml($html,'UTF-8');$pdf->setPaper('A4','portrait');$pdf->render();file_put_contents(__OUT__.'/'.$name.'.pdf',$pdf->output());
 }exit;
}
?><!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="stylesheet" href="assets/bootstrap.css"><link rel="stylesheet" href="public/css/global.css"><link rel="stylesheet" href="public/css/dashboard.css"><link rel="stylesheet" href="public/css/responsive_sistema.css"></head><body style="padding:20px">
<?php
$mode=$_GET['vista']??'reporte';
// La consulta real viene del navegador; las vistas no ejecutan SQL.
parse_str($_SERVER['QUERY_STRING']??'',$_GET);$mode=$_GET['vista']??'reporte';
if($mode==='historial'){$ventasBandeja=['filas'=>$fixture['historial'],'total'=>1,'pagina'=>1,'paginas'=>1];$contactosVentas=[];require __DIR__.'/app/views/telefonia/ventas_llamadas.php';}
elseif($mode==='supervision'){$scope='global';$f=['area'=>'asesores','persona_id'=>8,'desde'=>$d['periodo']['fecha_desde'],'hasta'=>$d['periodo']['fecha_hasta'],'direccion'=>'','resultado'=>'WHATSAPP','buscar'=>''];$data=['personas'=>[['id'=>8,'nombre'=>'Asesor Uno','activo'=>1]],'llamadas'=>$fixture['supervision'],'total'=>count($fixture['supervision']),'paginas'=>1,'pagina'=>1,'resultados'=>['WHATSAPP'=>'Se envió por WhatsApp']];$puedeAudio=false;$error='';require __DIR__.'/app/views/telefonia/supervision_llamadas.php';}
else{$actividad=$d;$errorActividad='';$urlActividadPdf='';require __DIR__.'/app/views/reportes/ventas_actividad.php';}
?></body></html>
'''
 script=script.replace('__BASE__',json.dumps(base)).replace('__AUTOLOAD__',json.dumps(str(R/'vendor/autoload.php').replace('\\','/'))).replace('__OUT__',json.dumps(str(OUT).replace('\\','/')))
 (P/'index.php').write_text(script,encoding='utf8')
 run=subprocess.run([PHP,str(P/'index.php'),'pdf'],capture_output=True,text=True,encoding='utf8');assert run.returncode==0 and not run.stderr,run.stdout+run.stderr
 server=subprocess.Popen([PHP,'-S','127.0.0.1:'+str(port),'-t',str(P)],stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL,creationflags=0x08000000 if os.name=='nt' else 0)
 try:
  for _ in range(30):
   try:urllib.request.urlopen(base,timeout=1);break
   except OSError:time.sleep(.1)
  env=dict(os.environ,IMPE_TEST_URL=base,IMPE_TEST_CONSULTA_OUT=str(OUT))
  subprocess.run(['node',str(R/'tests/ventas_consulta_doble_browser.cjs')],env=env,check=True)
 finally:server.terminate();server.wait(timeout=10)
 print('PASS: vistas reales y PDF de Reportes/Desempeño generados con Dompdf, sin acceso a producción.')
