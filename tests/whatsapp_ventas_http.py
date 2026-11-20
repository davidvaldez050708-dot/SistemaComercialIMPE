"""HTTP y Meta simulada; no envía mensajes ni consulta la cuenta real."""
from pathlib import Path
import http.cookiejar,json,os,socket,subprocess,sys,tempfile,time,urllib.error,urllib.parse,urllib.request
root=Path(__file__).resolve().parents[1]
schema,mine,other=sys.argv[1:]
assert schema.startswith('impe_wa_ventas_test_') and schema.replace('_','').isalnum() and mine.isdigit() and other.isdigit()
with socket.socket() as s:s.bind(('127.0.0.1',0));port=s.getsockname()[1]
env=dict(os.environ,IMPE_DB_HOST='127.0.0.1',IMPE_DB_USER=os.environ['MYSQL_USER'],IMPE_DB_PASSWORD=os.environ['MYSQL_PASSWORD'],IMPE_DB_NAME=schema,IMPE_BASE_URL='http://127.0.0.1:'+str(port)+'/')
def phpstr(v):return "'"+str(v).replace('\\','/').replace("'","\\'")+"'"
token='wa-ventas-csrf-fixture-only'
with tempfile.TemporaryDirectory(prefix='wa-ventas-http-') as tmp:
 router=Path(tmp)/'router.php';counter=Path(tmp)/'calls.txt';counter.write_text('0')
 router.write_text('''<?php
session_start();$uid=(int)($_GET['fixture_actor']??22);$role=[1=>1,2=>2,6=>6,22=>3,23=>3][$uid]??3;
$_SESSION['usuario_id']=$uid;$_SESSION['rol_id']=$role;$_SESSION['csrf_token']='''+phpstr(token)+''';
$_SESSION['permisos']=['whatsapp.ver','whatsapp.gestionar_conversaciones'];
if($role===3)$_SESSION['permisos']=array_merge($_SESSION['permisos'],['whatsapp.enviar','ventas.embudo.ver','whatsapp.responder_todas']);
if($role===1)$_SESSION['permisos'][]='whatsapp.gestionar_cuentas';
require '''+phpstr(root/'config/config.php')+''';
require '''+phpstr(root/'app/controllers/WhatsAppController.php')+''';
class VentasApiFixture extends WhatsAppCloudApiService {
 public function __construct(?mysqli $db=null){}
 public function obtenerEstadoConfiguracion(){return ['lista'=>true];}
 protected function listarPlantillasMeta(string $cuenta,string $nombre): array {
  return ['ok'=>true,'filas'=>[['name'=>'inicio_atencion_comercial','language'=>'es_MX','status'=>isset($_GET['fixture_pending'])?'PENDING':'APPROVED','category'=>'MARKETING','components'=>[['type'=>'BODY','text'=>'Hola, somos Fundación Red Educativa México. ¿Deseas que continuemos con tu registro?']]]]];
 }
 private function fixtureSend(){ $file='''+phpstr(counter)+''';$n=1+(int)file_get_contents($file);file_put_contents($file,(string)$n);return ['ok'=>true,'wamid'=>'ventas-fixture-'.$n,'respuesta'=>[]];}
 public function enviarPlantillaComercial(string $phone,string $destino,string $nombre,string $idioma): array {return $this->fixtureSend();}
 public function enviarTexto($phone,$to,$text){return $this->fixtureSend();}
}
class VentasControllerFixture extends WhatsAppController {protected function servicioWhatsApp(WhatsAppModel $m): WhatsAppCloudApiService {return new VentasApiFixture($m->conexionParaAutorizacion());}}
if(isset($_GET['fixture_open'])){ $db=(new Database())->connect(false);$db->query("UPDATE whatsapp_conversaciones SET ventana_servicio_hasta=DATE_ADD(NOW(),INTERVAL 1 DAY) WHERE id='''+mine+'''");}
$action=$_GET['action']??'';
if(!in_array($action,['contexto','conversaciones','mensajes','panel','crearConversacion','enviar','iniciarVentas','guardarPlantillaVentas'],true)){http_response_code(404);exit;}
(new VentasControllerFixture())->$action();
''',encoding='utf-8')
 server=subprocess.Popen(['php','-d','mysqli.default_port='+env['MYSQL_PORT'],'-S','127.0.0.1:'+str(port),str(router)],env=env,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
 try:
  for _ in range(100):
   try:
    with socket.create_connection(('127.0.0.1',port),timeout=.1):break
   except OSError:time.sleep(.05)
  clients={uid:urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar())) for uid in [1,2,6,22,23]}
  def call(action,uid=22,data=None,csrf=True,**params):
   url='http://127.0.0.1:'+str(port)+'/?'+urllib.parse.urlencode(dict(action=action,fixture_actor=uid,**params))
   req=urllib.request.Request(url,data=None if data is None else urllib.parse.urlencode(data).encode())
   if csrf:req.add_header('X-CSRF-Token',token)
   try:r=clients[uid].open(req,timeout=15)
   except urllib.error.HTTPError as e:r=e
   with r:return r.code,r.read().decode('utf-8')
  status,body=call('conversaciones');rows=json.loads(body)['conversaciones']
  assert status==200 and len(rows)==1 and str(rows[0]['id'])==mine
  assert all(c['prospecto_id'] and c['cuenta_tipo']=='EMPRESARIAL' for c in rows)
  assert call('mensajes',23,conversacion_id=mine)[0]==403
  assert call('contexto',data={'tipo':'prospecto','id':2})[0]==409
  assert call('crearConversacion',data={'telefono':'7770000099'})[0]==403
  for uid in [1,2,6]:
   status,body=call('conversaciones',uid);assert status==200 and len(json.loads(body)['conversaciones'])>=4
  status,html=call('panel',conversacion_id=mine)
  assert status==200 and 'Prospecto #1' in html and 'Asesor 22' in html and 'Enviar presentación institucional' in html
  assert 'Enviar plantilla de prueba' not in html
  payload={'conversacion_id':mine,'request_id':'ventas-request-0000000001'}
  assert call('iniciarVentas',23,data=payload)[0]==403
  assert call('iniciarVentas',data=payload,csrf=False)[0]==403
  assert call('iniciarVentas',data=payload,fixture_pending=1)[0]==409
  assert counter.read_text()=='0'
  payload['request_id']='ventas-request-0000000002'
  status,body=call('iniciarVentas',data=payload);assert status==200 and json.loads(body)['ok'],body
  assert counter.read_text()=='1'
  assert call('iniciarVentas',data=payload)[0]==200 and counter.read_text()=='1'
  assert call('iniciarVentas',data={**payload,'request_id':'ventas-request-0000000003'})[0]==409 and counter.read_text()=='1'
  assert call('iniciarVentas',data={**payload,'request_id':'ventas-request-0000000004'},fixture_open=1)[0]==409
  status,body=call('enviar',data={'conversacion_id':mine,'mensaje':'Respuesta de prueba','request_id':'ventas-text-00000000001'})
  assert status==200 and json.loads(body)['ok'] and counter.read_text()=='2',body
  assert call('guardarPlantillaVentas',data={'cuenta_id':1,'messaging_account_id':'2586216875220299','plantilla_nombre':'inicio_atencion_comercial','plantilla_idioma':'es_MX'})[0]==403
  print('PASS HTTP: cartera exclusiva, supervisión, etiquetas, canal empresarial, CSRF, aprobación, primer mensaje, idempotencia, antirrepetición y respuesta dentro de ventana; Meta simulada.')
 finally:server.terminate();server.wait(timeout=15)
