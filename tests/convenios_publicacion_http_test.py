"""Réplicas sintéticas: HTTP y SQL reales, sin usar configuración ni DB del CRM."""
import base64,io,json,os,pathlib,shutil,socket,subprocess,tempfile,time,urllib.request,urllib.error,urllib.parse,uuid,zipfile
R=pathlib.Path(__file__).resolve().parents[1]
php=os.environ.get('PHP_BINARY') or shutil.which('php');assert php
assert os.environ.get('IMPE_TEST_MYSQL_PORT'),'Sólo instancia MySQL aislada'
def blob(ref,path):return subprocess.check_output(['git','show',ref+':'+path],cwd=R)
with tempfile.TemporaryDirectory() as tmp:
 root=pathlib.Path(tmp)/'sitio';root.mkdir()
 archive=subprocess.check_output(['git','archive','--format=zip','7fea85f6386b524053dedea2264d6aab64790091'],cwd=R)
 with zipfile.ZipFile(io.BytesIO(archive)) as z:
  for name in z.namelist():
   if not name.startswith('vendor/'):z.extract(name,root)
 for p in ['app/helpers/PermissionHelper.php','app/helpers/SupervisionRolePolicy.php','app/helpers/AdminRolePolicy.php','app/services/AliadoRegistroService.php','app/controllers/ConvocatoriaController.php','app/controllers/CrearConvocatoriaController.php']:
  (root/p).parent.mkdir(parents=True,exist_ok=True);shutil.copyfile(R/p,root/p)
 for ref,p in [('d5cd6733','app/services/ConvocatoriaBorradorService.php'),('687ddd6c','app/services/ConvocatoriaQrDisenoService.php'),('932c3fbb','app/helpers/ConvocatoriaVentanaMensual.php')]:
  (root/p).write_bytes(blob(ref,p))
 # El flujo nacional probado nunca ejecuta operaciones internacionales.
 (root/'app/services/AliadoInternacionalService.php').write_text('<?php class AliadoInternacionalService {}')
 sock=socket.socket();sock.bind(('127.0.0.1',0));port=sock.getsockname()[1];sock.close()
 env=os.environ.copy();env.update(IMPE_CONVENIOS_FIXTURE_ROOT=str(root),IMPE_TEST_DB='impe_publicacion_test_'+str(os.getpid()),IMPE_TEST_ROOT_URL=f'http://127.0.0.1:{port}/',IMPE_CONVOCATORIA_DRAFT_DIR=str(pathlib.Path(tmp)/'borradores'))
 (root/'config/db_connection.php').write_text('''<?php
mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
define('ROOT_PATH',dirname(__DIR__));define('BASE_URL',getenv('IMPE_TEST_ROOT_URL'));
class Database {public function connect(bool $persistent=true){
 $name=getenv('IMPE_TEST_DB');$port=(int)getenv('IMPE_TEST_MYSQL_PORT');
 if(!$port||!preg_match('/^impe_publicacion_test_\\d+$/D',$name))throw new RuntimeException('Base de prueba obligatoria');
 $db=new mysqli('127.0.0.1',getenv('IMPE_TEST_MYSQL_USER')?:'root',getenv('IMPE_TEST_MYSQL_PASSWORD')?:'',null,$port);
 $db->query('CREATE DATABASE IF NOT EXISTS `'.$name.'` CHARACTER SET utf8mb4');$db->select_db($name);$db->set_charset('utf8mb4');return $db;
}}
''')
 (root/'router-test.php').write_text('''<?php
if(!in_array($_SERVER['REMOTE_ADDR'],['127.0.0.1','::1'],true)){http_response_code(404);exit;}
require __DIR__.'/config/db_connection.php';require __DIR__.'/app/helpers/PermissionHelper.php';
$_SESSION=['usuario_id'=>10,'rol_id'=>2,'rol'=>'Director de Operaciones','permisos'=>['convenios.ver','convocatorias.ver','convocatorias.crear'],'csrf_token'=>str_repeat('a',64)];
$role=$_GET['role']??'';
if($role==='revocado')$_SESSION['permisos']=['convenios.ver','convocatorias.ver'];
if($role==='ajeno')$_SESSION['usuario_id']=11;
if($role==='cc'){$_SESSION['usuario_id']=6;$_SESSION['rol_id']=6;$_SESSION['rol']='Cuenta Clave';}
if(($_GET['action']??'')==='render'){
 $puedeCrearDesdeConvenio=puedeCrearConvocatoriaDesdeConvenio();$convenios=[];$estados=[['id'=>2,'nombre'=>'Baja California']];$estructuraDisponible=true;
 require __DIR__.'/app/views/convocatorias/crear_desde_convenio.php';exit;
}
if(($_GET['controller']??'')==='convocatoria'){require __DIR__.'/app/controllers/ConvocatoriaController.php';$c=new ConvocatoriaController();}
else{require __DIR__.'/app/controllers/CrearConvocatoriaController.php';$c=new CrearConvocatoriaController();}
$action=$_GET['action']??'';if(!in_array($action,['borrador','guardarProgramasAliado','qrDiseno'],true)){http_response_code(404);exit;}$c->$action();
''')
 server=None
 def cli(mode):
  r=subprocess.run([php,str(R/'tests/convenios_publicacion_fixture.php'),mode],env=env,capture_output=True)
  assert r.returncode==0,(r.stdout.decode(errors='replace'),r.stderr.decode(errors='replace'))
  print(r.stdout.decode(errors='replace').strip())
 try:
  cli('--setup')
  log=open(pathlib.Path(tmp)/'server.log','wb')
  server=subprocess.Popen([php,'-S',f'127.0.0.1:{port}',str(root/'router-test.php')],cwd=root,env=env,stdout=log,stderr=log)
  for _ in range(100):
   try:
    with socket.create_connection(('127.0.0.1',port),timeout=.1):break
   except OSError:time.sleep(.05)
  def request(action,data=None,file=None,role='',controller='crearConvocatoria',extra=None):
   url=env['IMPE_TEST_ROOT_URL']+'?'+urllib.parse.urlencode(dict(action=action,controller=controller,role=role,**(extra or {})))
   headers={};body=None
   if data is not None:
    data=dict(data,csrf_token='a'*64)
    if file:
     boundary=uuid.uuid4().hex;parts=[]
     for k,v in data.items():parts.append(f'--{boundary}\r\nContent-Disposition: form-data; name="{k}"\r\n\r\n{v}\r\n'.encode())
     parts.append(f'--{boundary}\r\nContent-Disposition: form-data; name="imagen"; filename="test.png"\r\nContent-Type: image/png\r\n\r\n'.encode()+file+b'\r\n')
     body=b''.join(parts)+f'--{boundary}--\r\n'.encode();headers['Content-Type']='multipart/form-data; boundary='+boundary
    else:body=urllib.parse.urlencode(data).encode();headers['Content-Type']='application/x-www-form-urlencoded'
   try:
    with urllib.request.urlopen(urllib.request.Request(url,body,headers),timeout=30) as result:return result.status,result.read().decode()
   except urllib.error.HTTPError as e:return e.code,e.read().decode()
  code,html=request('render');assert code==200 and 'data-convenio-create-link' in html
  code,html=request('render',role='revocado');assert 'data-convenio-create-link' not in html
  for tipo,id in [('manual',20),('institucional',30)]:
   code,body=request('borrador',dict(operacion='abrir',aliado_tipo=tipo,aliado_id=id));assert code==200,(code,body);opened=json.loads(body)['datos']['borrador']
   did=opened['id'];version=opened['version']
   code,body=request('borrador',dict(operacion='cargar',id=did),role='ajeno');assert code==404,(code,body)
   code,body=request('borrador',dict(operacion='abrir',aliado_tipo=tipo,aliado_id=id),role='cc');assert code==403,(code,body)
   code,body=request('borrador',dict(operacion='abrir',aliado_tipo=tipo,aliado_id=id),role='revocado');assert code==403,(code,body)
   fields={p:dict(titulo='Convocatoria '+p,clasificacion='bachillerato|'+p,fecha_inicio='2026-01-01',fecha_termino='2099-12-31',estado='1') for p in ['bachillerato-general','ingles']}
   code,body=request('borrador',dict(operacion='guardar',id=did,version=version,operacion_id=uuid.uuid4().hex*2,programas=json.dumps(fields)));assert code==200,(code,body);version=json.loads(body)['datos']['version']
   png=base64.b64decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Wl6ZgAAAABJRU5ErkJggg==')
   for p in fields:
    code,body=request('borrador',dict(operacion='imagen',id=did,version=version,operacion_id=uuid.uuid4().hex*2,programa=p),file=png);assert code==200,(code,body);version=json.loads(body)['datos']['version']
   code,body=request('qrDiseno',extra=dict(aliado_tipo=tipo,aliado_id=id));assert code==200,(code,body)
   publish=dict(aliado_tipo=tipo,aliado_id=id,borrador_id=did,version=version)
   code,body=request('guardarProgramasAliado',publish,controller='convocatoria',role='revocado');assert code==403,(code,body)
   code,body=request('guardarProgramasAliado',publish,controller='convocatoria');assert code==200,(code,body);assert json.loads(body)['cantidad']==2
   code,again=request('guardarProgramasAliado',publish,controller='convocatoria');assert code==200 and json.loads(again)['ids']==json.loads(body)['ids'],'Reintento no duplica'
  cli('--verify')
 finally:
  if server:server.terminate();server.wait(timeout=10);log.close()
  try:cli('--drop')
  except Exception:pass
