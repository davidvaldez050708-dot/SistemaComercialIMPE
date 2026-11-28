<?php
/**
 * Analista: dos tramos de la SALIENTE Recepción -> Institución.
 * No altera grabacion_transferida_pbx.php, usada en las entrantes normales
 * y en los tramos de Recepción/Ventas.
 */
declare(strict_types=1);
session_start();
require_once dirname(__DIR__,2).'/app/helpers/TelefoniaPeticionContexto.php';
TelefoniaPeticionContexto::actualizar(false);
TelefoniaPeticionContexto::liberarSesion();
$root=dirname(__DIR__,2);
require_once $root.'/config/db_connection.php';
require_once $root.'/app/helpers/PermissionHelper.php';
require_once $root.'/app/models/RolModel.php';
require_once $root.'/app/services/ZadarmaRecordingService.php';
require_once $root.'/app/services/TelefoniaGrabacionesTransferenciaInstitucionService.php';
require_once $root.'/app/services/TelefoniaGrabacionSelector.php';
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
if(($_SERVER['REQUEST_METHOD']??'')!=='GET'){http_response_code(405);exit;}
$uid=(int)($_SESSION['usuario_id']??0);
if($uid<=0){http_response_code(401);exit;}

if(strcasecmp(trim((string)($_SESSION['rol']??'')),'Analista de Datos')!==0||
   !tienePermiso('telefonia.usar')||!tienePermiso('telefonia.recibir')||
   !tienePermiso('seguimientos_vinculacion.operar_propios')){
    http_response_code(403);exit;
}
$pbx=trim((string)($_GET['pbx_call_id']??''));
$seguimiento=filter_var($_GET['seguimiento_id']??'0',FILTER_VALIDATE_INT,
    ['options'=>['min_range'=>0]]) ;
if(!preg_match('/^out_[a-fA-F0-9]{32,64}$/D',$pbx)||$seguimiento===false){
    http_response_code(422);exit;
}
$range=trim((string)($_SERVER['HTTP_RANGE']??''));
if($range!==''&&!preg_match('/^bytes=\d*-\d*$/D',$range)){
    http_response_code(416);exit;
}
try{
    $db=(new Database())->connect();
    $q=$db->prepare("SELECT extension FROM telefonia_extensiones WHERE usuario_id=?
      AND proveedor='ZADARMA' AND activo=1 AND permite_entrantes=1 LIMIT 1");
    $q->bind_param('i',$uid);$q->execute();$row=$q->get_result()->fetch_assoc();$q->close();
    if(!$row)throw new DomainException('EXTENSION_NO_ACREDITADA');
    $provider=new ZadarmaRecordingService(false);
    $svc=new TelefoniaGrabacionesTransferenciaInstitucionService($db);
    $result=$svc->resolver($pbx,$uid,(string)$row['extension'],(int)$seguimiento,
        [$provider,'obtenerGrabacionPorCallId']);
    if(isset($_GET['verificar'])){
        unset($result['records']);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($result,JSON_UNESCAPED_UNICODE);
        exit;
    }
    $index=TelefoniaGrabacionSelector::indice(
        TelefoniaGrabacionSelector::referencias($result['records'],$result['segmentos_info']),$_GET);
    $audio=$provider->descargarGrabacion((string)$result['records'][$index]['link'],$range);
    require_once $root.'/app/services/TelefoniaAudioRespuesta.php';
    $response=TelefoniaAudioRespuesta::respuesta($audio,$range);
    http_response_code($response['status']);
    foreach($response['headers'] as $name=>$value)header($name.': '.$value);
    $extensionArchivo=TelefoniaAudioRespuesta::extension($response['headers']['Content-Type']??'');
    header('Content-Disposition: '.(($_GET['download']??'')==='1'?'attachment':'inline').'; filename="'.'transferencia-institucion-tramo-'.($index+1).'.'.$extensionArchivo.'"');
    header('Cache-Control: private, no-store');
    header('X-Content-Type-Options: nosniff');
    echo $response['body'];
}catch(DomainException $e){
    http_response_code(in_array($e->getCode(),[404,409],true)?$e->getCode():403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok'=>false,'estado'=>in_array($e->getCode(),[404,409],true)?'SELECCION_NO_DISPONIBLE':'ACCESO_NO_AUTORIZADO']);
}catch(Throwable $e){
    error_log('[grabacion_institucion_transferida] '.get_class($e));
    http_response_code(502);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok'=>false,'estado'=>'ERROR_TEMPORAL']);
}
