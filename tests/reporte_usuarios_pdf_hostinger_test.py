"""Exportador real con datos sintéticos, sin BD y con procesos externos deshabilitados."""
import json,os,pathlib,shutil,socket,subprocess,tempfile,time,urllib.request,urllib.error
import re
from pypdf import PdfReader
R=pathlib.Path(__file__).resolve().parents[1]
PHP=os.environ.get('PHP_BINARY','php')
VENDOR=pathlib.Path(os.environ.get('IMPE_TEST_VENDOR',str(R/'vendor')))
OUT=R/'tmp/pdfs';OUT.mkdir(parents=True,exist_ok=True)
with tempfile.TemporaryDirectory(prefix='impe-users-pdf-') as d:
 P=pathlib.Path(d)
 for name in ['app/controllers/ReporteAdministradorController.php','app/services/ReporteAdministradorPdfService.php','app/services/ReporteAdministradorDompdfService.php','app/services/ReporteNombreArchivoService.php','storage/templates/reportes/Administrador/Plantilla Reporte Admin.docx']:
  dest=P/name;dest.parent.mkdir(parents=True,exist_ok=True);shutil.copyfile(R/name,dest)
 # Dependencia exacta instalada, sin sustituir sus reglas de selección/resumen.
 name='app/services/ReporteAdministradorDataService.php'
 (P/name).write_bytes(subprocess.check_output(['git','show','051085238a2ba5936b1fb2058f64a51646869aaa:'+name],cwd=R))
 (P/'vendor').mkdir();(P/'vendor/autoload.php').write_text('<?php require '+json.dumps(str(VENDOR/'autoload.php').replace('\\','/'))+';',encoding='utf8')
 (P/'app/models').mkdir();(P/'app/helpers').mkdir()
 (P/'app/helpers/PermissionHelper.php').write_text('''<?php
 function esRolSupervisionNacional(){return in_array((int)($_SESSION['rol_id']??0),[1,2],true);}
 function tienePermiso($p){return empty($_GET['sin_permiso']);}
 ''',encoding='utf8')
 (P/'app/models/UsuarioModel.php').write_text('''<?php class UsuarioModel {
 function obtenerRolesActivos(){return [['id'=>1,'nombre'=>'Administrador'],['id'=>3,'nombre'=>'Asesor']];}
 function contarUsuarios(){return ['registrados'=>12,'activos'=>10,'inactivos'=>2];}
 function contarRoles(){return 2;}
 }''',encoding='utf8')
 (P/'app/models/ReporteAdministradorModel.php').write_text('''<?php class ReporteAdministradorModel {
 function __construct(){if(session_status()===PHP_SESSION_ACTIVE)throw new RuntimeException('La exportación bloquea la sesión');}
 function obtenerUsuariosConSeguimiento($roles){$r=[];for($i=1;$i<=12;$i++){
 $rol=$i<3?1:3;if($roles&&!in_array($rol,$roles,true))continue;
 $r[]=['usuario'=>'usuario'.$i,'nombre'=>$rol===1?'Administrador':'Asesor','apellidos'=>'Prueba Álvarez '.$i,
 'rol'=>$rol===1?'Administrador':'Asesor','estado'=>$i<11?1:0,'ultimo_acceso'=>'2026-11-06 10:00:00',
 'total_seguimientos'=>20,'acciones_pendientes'=>2,'acciones_vencidas'=>1,'sin_actividad'=>3,'mas_7_dias'=>1];}return $r;}
 function obtenerSeguimientosQueRequierenAtencion($roles){return [['responsable_nombre'=>'Prueba','responsable_apellidos'=>'Institucional',
 'nombre_entidad'=>'Institución sintética de evaluación <script>externo</script>','estado_nombre'=>'Morelos',
 'estado_seguimiento'=>'NUEVO','ultima_interaccion_at'=>null,'dias_sin_actividad'=>null,'proxima_accion_at'=>null]];}
 }''',encoding='utf8')
 (P/'index.php').write_text('''<?php
 date_default_timezone_set('America/Mexico_City');session_start();define('BASE_URL','/');
 $_SESSION=['usuario_id'=>99,'rol_id'=>(int)($_GET['rol']??1),'nombre'=>'Prueba','apellidos'=>'Sistema','rol'=>'Administrador'];
 if(isset($_GET['anonimo']))unset($_SESSION['usuario_id']);
 require __DIR__.'/app/controllers/ReporteAdministradorController.php';(new ReporteAdministradorController())->exportarPdf();
 ''',encoding='utf8')
 with socket.socket() as sock:sock.bind(('127.0.0.1',0));port=sock.getsockname()[1]
 base='http://127.0.0.1:'+str(port)
 log=open(OUT/'usuarios-php.log','w',encoding='utf8')
 server=subprocess.Popen([PHP,'-d','disable_functions=proc_open,exec,shell_exec,system,passthru','-S','127.0.0.1:'+str(port),'-t',str(P)],stdout=log,stderr=log,creationflags=0x08000000 if os.name=='nt' else 0)
 try:
  for _ in range(40):
   with socket.socket() as sock:
    if sock.connect_ex(('127.0.0.1',port))==0:break
   time.sleep(.1)
  for name,query in [('todos','todos_roles=1'),('asesores','roles%5B%5D=3')]:
   start=time.monotonic()
   with urllib.request.urlopen(base+'/?filtrar_roles=1&'+query,timeout=25) as response:
    pdf=response.read();assert pdf.startswith(b'%PDF-'),pdf[:400]
    assert response.headers['Content-Type']=='application/pdf'
    assert response.headers['Content-Disposition'].startswith('attachment; filename="Reporte_Usuarios_Corte_')
    assert int(response.headers['Content-Length'])==len(pdf)
    (OUT/('usuarios-'+name+'.pdf')).write_bytes(pdf)
   assert time.monotonic()-start<20,'PDF excedió el tiempo esperado'
  for query,expected in [('sin_permiso=1',403),('rol=6',403),('filtrar_roles=1&roles%5B%5D=999',400)]:
   try:urllib.request.urlopen(base+'/?'+query);raise AssertionError('Permite acceso/filtro inválido')
   except urllib.error.HTTPError as e:assert e.code==expected,(query,e.code)
 finally:server.terminate();server.wait(timeout=10);log.close()
 text=(OUT/'usuarios-php.log').read_text(encoding='utf8')
 assert 'PHP Warning' not in text and 'PHP Fatal' not in text,text
 assert not list((P/'storage/tmp/reportes/administrador').glob('reporte_*')),'Temporales sin limpiar'
 a=' '.join(page.extract_text() for page in PdfReader(OUT/'usuarios-todos.pdf').pages)
 b=' '.join(page.extract_text() for page in PdfReader(OUT/'usuarios-asesores.pdf').pages)
 assert all(re.search(r'\busuario'+str(i)+r'\b',a) for i in range(1,13))
 assert not re.search(r'\busuario[12]\b',b)
 assert all(re.search(r'\busuario'+str(i)+r'\b',b) for i in range(3,13))
 assert all(t in a for t in ['RESUMEN EJECUTIVO','PERSONAS Y ACCESO','ATENCIÓN REQUERIDA','HALLAZGOS ADMINISTRATIVOS'])
print('PASS: PDF real, filtros, cabeceras de descarga, permisos, sesión libre, limpieza y conversión sin proc_open/LibreOffice/Word/BD.')
