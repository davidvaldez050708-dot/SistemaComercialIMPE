<?php
declare(strict_types=1);
// NOT a production endpoint. Start only through start_http.py, on loopback.
$lab=getenv('IMPE_PHONE_LAB_ROOT');$port=(int)getenv('IMPE_PHONE_LAB_PORT');
if(!$lab||!in_array($port,[33180,33181,33182],true)||!in_array($_SERVER['REMOTE_ADDR']??'',['127.0.0.1','::1'],true)||
 ($_SERVER['HTTP_HOST']??'')!=='127.0.0.1:'.$port){http_response_code(404);exit;}
$meta=json_decode(file_get_contents($lab.'/lab.json'),true,32,JSON_THROW_ON_ERROR);
$c=json_decode(file_get_contents($lab.'/credentials.json'),true,32,JSON_THROW_ON_ERROR);
if(($meta['synthetic']??false)!==true||$c['host']!=='127.0.0.1'||$c['port']!==3308||$c['database']!=='impe_telefonia_lab'){http_response_code(404);exit;}
date_default_timezone_set('America/Mexico_City');
$site=$lab.'/site';$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
function fixtureReply(array $data,int $status=200):never {http_response_code($status);header('Content-Type: application/json');echo json_encode($data,JSON_THROW_ON_ERROR);exit;}
if(str_starts_with($path,'/fixture/')){
 $provided=$_SERVER['HTTP_X_FIXTURE_KEY']??'';
 if(!is_string($provided)||!hash_equals($c['fixture_key']??'unavailable',$provided))fixtureReply(['ok'=>false],403);
 require_once $site.'/config/db_connection.php';
 if($path==='/fixture/login'){
  $role=(string)($_GET['role']??'');$user=['VENTAS'=>41,'ANALISTA'=>42,'RECEPCION'=>43][$role]??0;
  if(!$user)fixtureReply(['ok'=>false],422);
  session_start();session_regenerate_id(true);$_SESSION=['usuario_id'=>$user,'csrf_token'=>bin2hex(random_bytes(24))];
  require_once $site.'/app/helpers/TelefoniaPeticionContexto.php';TelefoniaPeticionContexto::actualizar();
  fixtureReply(['ok'=>true,'user'=>$user,'csrf'=>$_SESSION['csrf_token'],'role'=>$role]);
 }
 if($path==='/fixture/event'){
  $event=json_decode((string)file_get_contents('php://input'),true,16,JSON_THROW_ON_ERROR);
  if(!is_array($event)||!preg_match('/^(?:out_)?lab_[a-zA-Z0-9_-]{6,80}$/D',(string)($event['pbx_call_id']??''))||
    !in_array($event['event']??'',['NOTIFY_OUT_START','NOTIFY_INTERNAL','NOTIFY_ANSWER','NOTIFY_OUT_END','NOTIFY_END','NOTIFY_RECORD'],true)||
    !in_array($event['internal']??'',['100','101','102'],true))fixtureReply(['ok'=>false],422);
  require_once $site.'/app/services/ZadarmaWebhookEventStoreService.php';
  $ok=(new ZadarmaWebhookEventStoreService(Database::compartida()))->registrar($event);
  fixtureReply(['ok'=>(bool)$ok]);
 }
 if($path==='/fixture/inspect'){
  $id=(string)($_GET['pbx']??'');if(!preg_match('/^(?:out_)?lab_[a-zA-Z0-9_-]{6,80}$/D',$id))fixtureReply(['ok'=>false],422);
  $db=Database::compartida();$counts=[];
  foreach(['telefonia_ventas_resultados','telefonia_recepcion_resultados','telefonia_analista_entrantes','telefonia_zadarma_eventos'] as $table){
   $s=$db->prepare('SELECT COUNT(*) n FROM '.$table.' WHERE pbx_call_id=BINARY ?');$s->bind_param('s',$id);$s->execute();$counts[$table]=(int)$s->get_result()->fetch_assoc()['n'];$s->close();
  }
  fixtureReply(['ok'=>true,'counts'=>$counts]);
 }
 fixtureReply(['ok'=>false],404);
}
$files=['/prueba_telefonia/api/sesion_local.php','/prueba_telefonia/api/borrador_llamada.php',
 '/prueba_telefonia/api/zadarma_estado_llamada.php','/prueba_telefonia/api/resultado_recepcion_marketing.php'];
if(in_array($path,$files,true)||$path==='/index.php'){
 if(($_SERVER['HTTP_X_FIXTURE_DROP_REPLY']??'')==='1'){
  ob_start();register_shutdown_function(static function(){if(http_response_code()<300){ob_clean();http_response_code(503);header('Content-Type: application/json');echo '{"ok":false,"mensaje":"Synthetic lost ACK"}';}});
 }
 if($path!=='/index.php'){require $site.$path;exit;}
 session_start();require_once $site.'/app/helpers/TelefoniaPeticionContexto.php';TelefoniaPeticionContexto::actualizar();
 $routes=['telefonia.guardarResultadoVenta'=>['TelefoniaController','guardarResultadoVenta'],
  'seguimientoVinculacion.registrarLlamadaEntrante'=>['SeguimientoVinculacionController','registrarLlamadaEntrante']];
 $route=$routes[($_GET['controller']??'').'.'.($_GET['action']??'')]??null;if(!$route)fixtureReply(['ok'=>false],404);
 require_once $site.'/app/controllers/'.$route[0].'.php';(new $route[0])->{$route[1]}();exit;
}
fixtureReply(['ok'=>false],404);
