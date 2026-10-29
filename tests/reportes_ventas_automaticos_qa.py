"""Controlador, vista y JS reales; servicios sintéticos, sin DB ni telefonía."""
import io,json,os,pathlib,shutil,socket,subprocess,tarfile,tempfile,time,urllib.request
R=pathlib.Path(__file__).resolve().parents[1];PHP=os.environ.get('PHP_BINARY','php');OUT=R/'tmp/reportes-auto-qa';OUT.mkdir(parents=True,exist_ok=True)
with tempfile.TemporaryDirectory(prefix='impe-reportes-auto-') as folder:
    P=pathlib.Path(folder)
    with tarfile.open(fileobj=io.BytesIO(subprocess.check_output(['git','archive','08451cb7','app'],cwd=R))) as archive:archive.extractall(P,filter='data')
    for path in ['app/controllers/VentasActividadController.php','app/views/reportes/ventas_actividad.php','app/services/TelefoniaVentasLlamadasService.php','app/services/TelefoniaResultadoVentasService.php','app/services/TelefoniaResultadoVentasCatalogo.php','app/services/VentasActividadMetricas.php']:
        target=P/path;target.parent.mkdir(parents=True,exist_ok=True);shutil.copyfile(R/path,target)
    for path,rev in {'app/services/TelefoniaLimpiezaGuard.php':'6ba06aa6','app/views/telefonia/ventas_pendientes_resumen.php':'77d57ada'}.items():
        (P/path).write_bytes(subprocess.check_output(['git','show',rev+':'+path],cwd=R))
    shutil.copyfile(R/'tests/fixtures/ventas_auto_services.php',P/'app/services/VentasActividadService.php')
    (P/'app/services/DesempenoPdfService.php').write_text("<?php class DesempenoPdfService {public function generar($data){throw new LogicException('No se generan PDF en esta prueba de navegación');}}",encoding='utf8')
    (P/'app/services/VentasDesempenoService.php').write_text("<?php class VentasDesempenoService {public function preparar($f){throw new LogicException('No modificar Desempeño');}}",encoding='utf8')
    (P/'app/helpers/AvatarHelper.php').write_text('''<?php function renderAvatarUsuario(...$args){return '<span aria-hidden="true">●</span>';}''',encoding='utf8')
    (P/'config').mkdir();(P/'config/db_connection.php').write_text("<?php class Database {public function connect(){throw new LogicException('Sin acceso a producción');}}",encoding='utf8')
    shutil.copytree(R/'public/css',P/'public/css');(P/'public/javascript').mkdir()
    shutil.copyfile(R/'public/javascript/ventas_actividad.js',P/'public/javascript/ventas_actividad.js')
    for path in ['public/css/ventas_actividad.css','public/javascript/reportes_pdf_descarga.js']:
        (P/path).write_bytes(subprocess.check_output(['git','show','77d57ada:'+path],cwd=R))
    (P/'assets').mkdir();(P/'assets/bootstrap.css').write_bytes(urllib.request.urlopen('https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css',timeout=30).read())
    for name in ['sidebar.php','topbar.php']:(P/'app/views/layout'/name).write_text('',encoding='utf8')
    (P/'app/views/layout/dashboard_head.php').write_text('<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="stylesheet" href="assets/bootstrap.css"><link rel="stylesheet" href="public/css/global.css"><link rel="stylesheet" href="public/css/dashboard.css"><link rel="stylesheet" href="public/css/responsive_sistema.css"></head><body style="padding:20px">',encoding='utf8')
    (P/'app/views/layout/dashboard_footer.php').write_text('</body></html>',encoding='utf8')
    with socket.socket() as sock:sock.bind(('127.0.0.1',0));port=sock.getsockname()[1]
    base='http://127.0.0.1:'+str(port)+'/'
    (P/'index.php').write_text("<?php date_default_timezone_set('America/Mexico_City');error_reporting(E_ALL);define('ROOT_PATH',__DIR__);define('BASE_URL',"+json.dumps(base)+");$_SESSION=['usuario_id'=>1,'rol_id'=>1,'csrf_token'=>'prueba'];require __DIR__.'/app/controllers/VentasActividadController.php';(new VentasActividadController())->mostrar();",encoding='utf8')
    (P/'shell.php').write_text('<!doctype html><html><body><script>window.phoneSentinel={active:true,token:"llamada-inalterada"};window.hostLoads=1;</script><iframe id="crmFrame" src="index.php?controller=reporte&action=ventas" style="width:100%;height:900px;border:0"></iframe></body></html>',encoding='utf8')
    # El primer GET debe generar por sí solo; los enlaces heredados generar=0 tampoco lo desactivan.
    server=subprocess.Popen([PHP,'-S','127.0.0.1:'+str(port),'-t',str(P)],stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL,creationflags=0x08000000 if os.name=='nt' else 0)
    try:
        for _ in range(30):
            try:urllib.request.urlopen(base,timeout=1);break
            except OSError:time.sleep(.1)
        for suffix in ['','?generar=0','?generar=1']:
            html=urllib.request.urlopen(base+suffix).read().decode();assert 'Buzones de voz' in html and 'Prepara tu reporte' not in html and 'Actualiza el reporte' not in html
        env=dict(os.environ,IMPE_TEST_URL=base,IMPE_TEST_AUTO_OUT=str(OUT))
        subprocess.run(['node',str(R/'tests/reportes_ventas_automaticos_browser.cjs')],env=env,check=True)
    finally:server.terminate();server.wait(timeout=10)
print('PASS: controlador genera sin parámetro inicial, sin SQL ni llamadas externas; vistas reales en escritorio/móvil y dentro del iframe del CRM.')
