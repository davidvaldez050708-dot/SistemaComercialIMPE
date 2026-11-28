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
function fixtureReference(string $value):bool{return (bool)preg_match('/^(?:(?:out_)?lab_[a-zA-Z0-9_-]{6,80}|out_[a-f0-9]{32})$/D',$value);}
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
  if(!is_array($event)||!fixtureReference((string)($event['pbx_call_id']??''))||
    !in_array($event['event']??'',['NOTIFY_OUT_START','NOTIFY_INTERNAL','NOTIFY_ANSWER','NOTIFY_OUT_END','NOTIFY_END','NOTIFY_RECORD'],true)||
    (!in_array($event['internal']??'',['100','101','102'],true)&&
     !(($event['internal']??null)===''&&($event['event']??'')==='NOTIFY_OUT_END')))fixtureReply(['ok'=>false],422);
  require_once $site.'/app/services/ZadarmaWebhookEventStoreService.php';
  $ok=(new ZadarmaWebhookEventStoreService(Database::compartida()))->registrar($event);
  fixtureReply(['ok'=>(bool)$ok]);
 }
 if($path==='/fixture/inspect'){
  $id=(string)($_GET['pbx']??'');if(!fixtureReference($id))fixtureReply(['ok'=>false],422);
  $db=Database::compartida();$counts=[];
  foreach(['telefonia_ventas_resultados','telefonia_recepcion_resultados','telefonia_analista_entrantes','telefonia_zadarma_eventos'] as $table){
   $s=$db->prepare('SELECT COUNT(*) n FROM '.$table.' WHERE pbx_call_id=BINARY ?');$s->bind_param('s',$id);$s->execute();$counts[$table]=(int)$s->get_result()->fetch_assoc()['n'];$s->close();
  }
  if(isset($_GET['followup'])){
   $followup=(int)$_GET['followup'];$s=$db->prepare('SELECT COUNT(*) n FROM interacciones_vinculacion WHERE seguimiento_id=? AND usuario_id=42');
   $s->bind_param('i',$followup);$s->execute();$counts['interacciones_vinculacion']=(int)$s->get_result()->fetch_assoc()['n'];$s->close();
   $s=$db->prepare("SELECT SUM(estado='CONFIRMADA') confirmadas,SUM(estado='RECHAZADA') rechazadas FROM telefonia_verificacion_evidencias WHERE seguimiento_id=? AND usuario_id=42");
   $s->bind_param('i',$followup);$s->execute();$evidence=$s->get_result()->fetch_assoc();$s->close();
   $counts['evidencias_confirmadas']=(int)$evidence['confirmadas'];$counts['evidencias_rechazadas']=(int)$evidence['rechazadas'];
   require_once $site.'/app/models/SeguimientoVinculacionModel.php';
   $counts['efectivas_del_dia']=(new SeguimientoVinculacionModel($db))->obtenerResumenVerificacionTelefonicaDia(42,date('Y-m-d'))['verificaciones_efectivas'];
  }
  fixtureReply(['ok'=>true,'counts'=>$counts]);
 }
 if($path==='/fixture/callback'){
  if(($_SERVER['REQUEST_METHOD']??'')!=='POST')fixtureReply(['ok'=>false],405);
  $body=json_decode((string)file_get_contents('php://input'),true,8,JSON_THROW_ON_ERROR);
  $reference=(string)($body['pbx']??'');
  if(!preg_match('/^out_lab_[a-zA-Z0-9_-]{6,80}$/D',$reference))fixtureReply(['ok'=>false],422);
  $db=Database::compartida();$db->begin_transaction();
  try{
   $s=$db->prepare("INSERT INTO telefonia_disponibilidad(origen_usuario,origen_extension,destino_usuario,destino_extension,referencia,numero,ruta_json,estado,version,expires_at,created_at,updated_at,telefono,devolucion) VALUES(43,'100',42,'102',?,'5550000101','{}','FALLIDA',1,DATE_ADD(UTC_TIMESTAMP(),INTERVAL 1 DAY),UTC_TIMESTAMP(),UTC_TIMESTAMP(),'5550000101','PENDIENTE')");
   $s->bind_param('s',$reference);$s->execute();$task=(int)$s->insert_id;$s->close();
   $bound=!empty($body['reserve_intent'])?null:$reference;
   $state=$bound===null?'PREPARADO':'EN_CURSO';
   $s=$db->prepare("INSERT INTO telefonia_devolucion_intentos(solicitud_id,version,usuario_id,extension,numero,desde_evento_id,pbx_call_id,estado,created_at,updated_at) VALUES(?,1,42,'102','5550000101',0,?,?,UTC_TIMESTAMP(),UTC_TIMESTAMP())");
   $s->bind_param('iss',$task,$bound,$state);$s->execute();$attempt=(int)$s->insert_id;$s->close();$db->commit();
   fixtureReply(['ok'=>true,'attempt'=>$attempt]);
  }catch(Throwable $e){$db->rollback();throw $e;}
 }
 if($path==='/fixture/followup-stage'){
  if(($_SERVER['REQUEST_METHOD']??'')!=='POST')fixtureReply(['ok'=>false],405);
  $body=json_decode((string)file_get_contents('php://input'),true,8,JSON_THROW_ON_ERROR);$id=(int)($body['id']??0);
  $s=Database::compartida()->prepare("UPDATE seguimientos_vinculacion SET estado_seguimiento='ESPERANDO_RESPUESTA' WHERE id=? AND analista_id=42 AND activo=1");
  $s->bind_param('i',$id);$s->execute();$ok=$s->affected_rows===1;$s->close();fixtureReply(['ok'=>$ok]);
 }
 fixtureReply(['ok'=>false],404);
}
$files=['/prueba_telefonia/api/sesion_local.php','/prueba_telefonia/api/borrador_llamada.php',
 '/prueba_telefonia/api/zadarma_estado_llamada.php','/prueba_telefonia/api/resultado_recepcion_marketing.php',
 '/prueba_telefonia/api/vincular_interaccion_zadarma.php'];
if(in_array($path,$files,true)||$path==='/index.php'){
 if(($_SERVER['HTTP_X_FIXTURE_DROP_REPLY']??'')==='1'){
  ob_start();register_shutdown_function(static function(){if(http_response_code()<300){ob_clean();http_response_code(503);header('Content-Type: application/json');echo '{"ok":false,"mensaje":"Synthetic lost ACK"}';}});
 }
 if($path!=='/index.php'){require $site.$path;exit;}
 session_start();require_once $site.'/app/helpers/TelefoniaPeticionContexto.php';TelefoniaPeticionContexto::actualizar();
 $routes=['telefonia.guardarResultadoVenta'=>['TelefoniaController','guardarResultadoVenta'],
  'telefonia.devolucion'=>['TelefoniaController','devolucion'],
  'seguimientoVinculacion.registrarInteraccionTrabajo'=>['SeguimientoVinculacionController','registrarInteraccionTrabajo'],
  'seguimientoInteraccion.registrarInformativa'=>['SeguimientoInteraccionController','registrarInformativa'],
  'seguimientoVinculacion.registrarLlamadaEntrante'=>['SeguimientoVinculacionController','registrarLlamadaEntrante']];
 $route=$routes[($_GET['controller']??'').'.'.($_GET['action']??'')]??null;if(!$route)fixtureReply(['ok'=>false],404);
 require_once $site.'/app/controllers/'.$route[0].'.php';(new $route[0])->{$route[1]}();exit;
}
fixtureReply(['ok'=>false],404);
